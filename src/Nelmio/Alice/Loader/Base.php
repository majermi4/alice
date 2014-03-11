<?php

/*
 * This file is part of the Alice package.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nelmio\Alice\Loader;

use Symfony\Component\Form\Util\FormUtil;
use Symfony\Component\PropertyAccess\StringUtil;
use Psr\Log\LoggerInterface;
use Nelmio\Alice\ORMInterface;
use Nelmio\Alice\LoaderInterface;
use Nelmio\Alice\Instances\Collection;
use Nelmio\Alice\Instances\Fixture;
use Nelmio\Alice\Instances\FixtureBuilder;
use Nelmio\Alice\Instances\Instantiator;
use Nelmio\Alice\Instances\Processor;
use Nelmio\Alice\Util\FlagParser;
use Nelmio\Alice\Util\TypeHintChecker;

/**
 * Loads fixtures from an array or php file
 *
 * The php code if $data is a file has access to $loader->fake() to
 * generate data and must return an array of the format below.
 *
 * The array format must follow this example:
 *
 *     array(
 *         'Namespace\Class' => array(
 *             'name' => array(
 *                 'property' => 'value',
 *                 'property2' => 'value',
 *             ),
 *             'name2' => array(
 *                 [...]
 *             ),
 *         ),
 *     )
 */
class Base implements LoaderInterface
{
    /**
     * @var Collection
     */
    protected $objects;

    /**
     * @var ORMInterface
     */
    protected $manager;

    /**
     * @var array
     */
    private $uniqueValues = array();

    /**
     * @var callable|LoggerInterface
     */
    private $logger;

    /**
     * @param string $locale default locale to use with faker if none is
     *      specified in the expression
     * @param array $providers custom faker providers in addition to the default
     *      ones from faker
     * @param int $seed a seed to make sure faker generates data consistently across
     *      runs, set to null to disable
     */
    public function __construct($locale = 'en_US', array $providers = array(), $seed = 1)
    {
        $this->objects         = new Collection;
        $this->typeHintChecker = new TypeHintChecker;
        $this->processor       = new Processor\Processor($this->objects, $providers, $locale);

        $this->fixtureBuilder = new FixtureBuilder\FixtureBuilder(array(
            new FixtureBuilder\Methods\RangeName(),
            new FixtureBuilder\Methods\ListName(),
            new FixtureBuilder\Methods\SimpleName()
            ));

        $this->instantiator = new Instantiator\Instantiator(array(
            new Instantiator\Methods\Unserialize(),
            new Instantiator\Methods\ReflectionWithoutConstructor(),
            new Instantiator\Methods\ReflectionWithConstructor($this->processor, $this->typeHintChecker),
            new Instantiator\Methods\EmptyConstructor(),
            ), $this->processor);

        if (is_numeric($seed)) {
            mt_srand($seed);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function load($dataOrFilename)
    {
        // ensure our data is loaded
        $data = !is_array($dataOrFilename) ? $this->parseFile($dataOrFilename) : $dataOrFilename;

        // create fixtures
        $newFixtures = $this->buildFixtures($data);

        // instantiate fixtures
        $this->instantiateFixtures($newFixtures);

        // populate objects
        $objects = array();
        foreach ($newFixtures as $fixture) {
            $this->populateObject($fixture);
            
            // add the object in the object store unless it's local
            if (!isset($fixture->getClassFlags()['local']) && !isset($fixture->getNameFlags()['local'])) {
                $objects[$fixture->getName()] = $this->getReference($fixture->getName());
            }
        }

        return $objects;
    }

    /**
     * {@inheritDoc}
     */
    public function getReference($name, $property = null)
    {
        return $this->objects->find($name, $property);
    }

    /**
     * {@inheritDoc}
     */
    public function getReferences()
    {
        return $this->objects->toArray();
    }

    /**
     * {@inheritDoc}
     */
    public function setProviders(array $providers)
    {
        $this->processor->setProviders($providers);
    }

    /**
     * {@inheritDoc}
     */
    public function setReferences(array $objects)
    {
        $this->objects->clear();
        foreach ($objects as $name => $object) {
            $this->objects->set($name, $object);
        }
    }

    /**
     * parses a file at the given filename
     *
     * @param string filename
     * @return array data
     */
    protected function parseFile($filename)
    {
        $loader = $this;
        $includeWrapper = function() use ($filename, $loader) {
            ob_start();
            $res = include $filename;
            ob_end_clean();

            return $res;
        };

        $data = $includeWrapper();
        if (!is_array($data)) {
            throw new \UnexpectedValueException("Included file \"{$filename}\" must return an array of data");
        }
        return $data;
    }

    /**
     * builds a collection of fixtures
     *
     * @param array $rawData
     * @return Collection
     */
    protected function buildFixtures(array $rawData)
    {
        $fixtures = array();

        foreach ($rawData as $class => $specs) {
            $this->log('Loading '.$class);
            foreach ($specs as $name => $spec) {
                $fixtures = array_merge($fixtures, $this->fixtureBuilder->build($class, $name, $spec));
            }
        }
        
        return $fixtures;
    }

    /**
     * creates an empty instance for each fixture, and adds it to our object collection
     *
     * @param array $fixtures
     */
    protected function instantiateFixtures(array $fixtures)
    {
        foreach ($fixtures as $fixture) {
            $this->objects->set(
                $fixture->getName(), 
                $this->instantiator->instantiate($fixture)
                );
        }
    }

    private function populateObject(Fixture $fixture)
    {
        $object = $this->getReference($fixture->getName());
        $class  = $fixture->getClass();
        $name   = $fixture->getName();

        $variables = array();

        if ($fixture->hasCustomSetter()) {
            if (!method_exists($object, $fixture->getCustomSetter())) {
                throw new \RuntimeException('Setter ' . $fixture->getCustomSetter() . ' not found in object');
            }
            $customSetter = $fixture->getCustomSetter()->getValue();
        }

        foreach ($fixture->getProperties() as $property) {
            $key = $property->getName();
            $val = $property->getValue();

            if (is_array($val) && '{' === key($val)) {
                throw new \RuntimeException('Misformatted string in object '.$name.', '.$key.'\'s value should be quoted if you used yaml');
            }

            if (isset($property->getNameFlags()['unique'])) {
                $i = $uniqueTriesLimit = 128;

                do {
                    // process values
                    $generatedVal = $this->processor->process($property, $variables, $fixture->getValueForCurrent());

                    if (is_object($generatedVal)) {
                        $valHash = spl_object_hash($generatedVal);
                    } elseif (is_array($generatedVal)) {
                        $valHash = hash('md4', serialize($generatedVal));
                    } else {
                        $valHash = $generatedVal;
                    }
                } while (--$i > 0 && isset($this->uniqueValues[$class . $key][$valHash]));

                if (isset($this->uniqueValues[$class . $key][$valHash])) {
                    throw new \RuntimeException("Couldn't generate random unique value for $class: $key in $uniqueTriesLimit tries.");
                }

                $this->uniqueValues[$class . $key][$valHash] = true;
            } else {
                $generatedVal = $this->processor->process($property, $variables, $fixture->getValueForCurrent());
            }

            // add relations if available
            if (is_array($generatedVal) && $method = $this->findAdderMethod($object, $key)) {
                foreach ($generatedVal as $rel) {
                    $rel = $this->typeHintChecker->check($object, $method, $rel);
                    $object->{$method}($rel);
                }
            } elseif (isset($customSetter)) {
                $object->$customSetter($key, $generatedVal);
                $variables[$key] = $generatedVal;
            } elseif (is_array($generatedVal) && method_exists($object, $key)) {
                foreach ($generatedVal as $num => $param) {
                    $generatedVal[$num] = $this->typeHintChecker->check($object, $key, $param, $num);
                }
                call_user_func_array(array($object, $key), $generatedVal);
                $variables[$key] = $generatedVal;
            } elseif (method_exists($object, 'set'.$key)) {
                $generatedVal = $this->typeHintChecker->check($object, 'set'.$key, $generatedVal);
                if(!is_callable(array($object, 'set'.$key))) {
                    $refl = new \ReflectionMethod($object, 'set'.$key);
                    $refl->setAccessible(true);
                    $refl->invoke($object, $generatedVal);
                } else {
                    $object->{'set'.$key}($generatedVal);
                }
                $variables[$key] = $generatedVal;
            } elseif (property_exists($object, $key)) {
                $refl = new \ReflectionProperty($object, $key);
                $refl->setAccessible(true);
                $refl->setValue($object, $generatedVal);

                $variables[$key] = $generatedVal;
            } else {
                throw new \UnexpectedValueException('Could not determine how to assign '.$key.' to a '.$class.' object');
            }
        }
    }

    private function findAdderMethod($obj, $key)
    {
        if (method_exists($obj, $method = 'add'.$key)) {
            return $method;
        }

        if (class_exists('Symfony\Component\PropertyAccess\StringUtil') && method_exists('Symfony\Component\PropertyAccess\StringUtil', 'singularify')) {
            foreach ((array) StringUtil::singularify($key) as $singularForm) {
                if (method_exists($obj, $method = 'add'.$singularForm)) {
                    return $method;
                }
            }
        } elseif (class_exists('Symfony\Component\Form\Util\FormUtil') && method_exists('Symfony\Component\Form\Util\FormUtil', 'singularify')) {
            foreach ((array) FormUtil::singularify($key) as $singularForm) {
                if (method_exists($obj, $method = 'add'.$singularForm)) {
                    return $method;
                }
            }
        }

        if (method_exists($obj, $method = 'add'.rtrim($key, 's'))) {
            return $method;
        }

        if (substr($key, -3) === 'ies' && method_exists($obj, $method = 'add'.substr($key, 0, -3).'y')) {
            return $method;
        }
    }

    public function setORM(ORMInterface $manager)
    {
        $this->manager = $manager;
        $this->typeHintChecker->setORM($manager);
    }

    /**
     * Set the logger callable to execute with the log() method.
     *
     * @param callable|LoggerInterface $logger
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

   /**
     * Logs a message using the logger.
     *
     * @param string $message
     */
   public function log($message)
   {
    if ($this->logger instanceof LoggerInterface) {
        $this->logger->debug($message);
    } elseif ($logger = $this->logger) {
        $logger($message);
    }
}
}
