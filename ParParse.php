<?php

/**
 * This file is part of the ParParse package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author     Jordan Halterman <jordan.halterman@gmail.com>
 * @copyright  2012 Jordan Halterman
 * @license    MIT License
 */

/**
 * Core PhpARgumentsPARSEr.
 *
 * @author Jordan Halterman <jordan.halterman@gmail.com>
 */
class ParParse {

  /**
   * The program description. This is used in generating usage text.
   *
   * @var string
   */
  private $description = '';

  /**
   * Optional text describing the usage of the command. If the usage text
   * is empty then the parser will generate usage text.
   *
   * @var string|null
   */
  private $usage = NULL;

  /**
   * An array of parsable element definitions.
   *
   * @var array
   */
  private $elements = array();

  /**
   * An array of unique parser names. This is used to ensure element
   * machine-names are not duplicated.
   *
   * @var array
   */
  private $uniqueNames = array();

  /**
   * Constructor.
   *
   * @param string $description
   *   The program description, used in generating usage text.
   * @param string|null $usage
   *   Optional usage text overriding the generated usage text.
   */
  public function __construct($description = '', $usage = NULL) {
    $this->description = $description;
    $this->usage = $usage;
  }

  /**
   * Adds a new parsable element to the parser.
   *
   * @param ParParseElement $element
   *   The parsable element definition object.
   *
   * @return ParParseElement
   *   The added element.
   */
  public function addElement(ParParseElementInterface $element) {
    if (array_search($element->getName(), $this->uniqueNames) !== FALSE) {
      throw new ParParseException('Cannot duplicate element name '. $element->getName() .'. An element with that name already exists.');
    }
    $this->elements[] = $element;
    $this->uniqueNames[] = $element->getName();
    return $element;
  }

  /**
   * Adds an argument definition to the argument parser.
   *
   * @param string $name
   *   The argument's machine-name, used to reference the argument value.
   * @param int $arity
   *   The number of arguments expected by the argument. Defaults to 1.
   * @param array $options
   *   An optional associative array of additional argument options.
   */
  public function addArgument($name, $arity = 1, array $options = array()) {
    $options += array('arity' => $arity);
    return $this->addElement(new ParParseArgument($name, $options));
  }

  /**
   * Adds a flag definition to the argument parser.
   *
   * Note that flags are simply options with an arity of 0. Thus, we
   * simply add and return a boolean option.
   *
   * @param string $name
   *   The flag long name.
   * @param string|null $alias
   *   The flag short alias.
   * @param array $options
   *   An optional associative array of additional flag options.
   */
  public function addFlag($name, $alias = NULL, array $options = array()) {
    return $this->addOption($name, $alias, $options)->arity(0);
  }

  /**
   * Adds an option definition to the argument parser.
   *
   * @param string $name
   *   The option's long machine-name, with or without two dashes.
   * @param string|null $alias
   *   The option's short alias, with or without one dash.
   * @paam array $options
   *   An associative array of additional option options.
   */
  public function addOption($name, $alias = NULL, array $options = array()) {
    return $this->addElement(new ParParseOption($name, $alias, $options));
  }

  /**
   * Parses command-line arguments.
   *
   * @param array|null $args
   *   An array of command line arguments. If no arguments are given
   *   then the default $argv arguments are used.
   *
   * @return ParParseResult
   *   A result instance from which result values can be retrieved.
   */
  public function parse(array $args = NULL) {
    if (!isset($args)) {
      global $argv;
      if (!isset($argv)) {
        throw new ArgParserException('Cannot parse arguments. No arguments given.');
      }
      else {
        $args = $argv;
      }
    }

    $command = $args[0];
    $args = array_slice($args, 1);

    // First check for the special '--help' option.
    if (in_array('--help', $args) || in_array('-h', $args)) {
      $this->printHelp($command);
      exit(0);
    }

    // Note that the order in which elements are parsed is essential to
    // ensure conflicts do not arise. We have to parse options and then
    // arguments, so we separate the elements and process them separately.
    try {
      $results = $options = $arguments = array();
      foreach ($this->elements as $element) {
        if ($element instanceof ParParseArgumentInterface) {
          $arguments[] = $element;
        }
        else if ($element instanceof ParParseOptionInterface) {
          $options[] = $element;
        }
      }

      foreach ($options as $option) {
        $args = array_values($args);
        $results[$option->getName()] = $option->parse($args);
      }

      // Positional arguments are parsed a little differently. Here we
      // pass an additional argument indicating whether this is the last
      // positional argument to be parsed. This is because only the last
      // positional argument can have default values.
      $num_args = count($arguments);
      for ($i = 0; $i < $num_args; $i++) {
        $arg = $arguments[$i];
        $args = array_values($args);
        $last_arg = !isset($arguments[$i+1]);
        $results[$arguments[$i]->getName()] = $arguments[$i]->parse($args, $last_arg);
      }
      return new ParParseResult($results);
    }
    catch (ParParseException $e) {
      echo $e->getMessage() . "\n\n";
      exit($this->printHelp($command));
    }
  }

  /**
   * Prints command-line help text.
   *
   * @param string $command
   *   The base string used to execute the command.
   *
   * @return string
   *   A help text string.
   */
  private function printHelp($command) {
    if (!is_null($this->usage)) {
      echo $this->usage;
    }

    $help = array();
    $usage = 'Usage: '. $command;
    foreach ($this->elements as $element) {
      $usage .= ' '. $element->printUsage();
    }

    $usage .= "\n";
    $help[] = $usage;

    $help[] = '-h|--help                               Display command usage information.'."\n";

    $arguments = $options = array();
    foreach ($this->elements as $element) {
      if ($element instanceof ParParseArgumentInterface) {
        $arguments[] = $element;
      }
      else if ($element instanceof ParParseOptionInterface) {
        $options[] = $element;
      }
    }

    $help[] = "\n";
    $indent = '  ';
    $help[] = 'Arguments:'."\n";
    foreach ($arguments as $arg) {
      $help[] = $arg->printHelp($indent) . "\n";
    }

    $help[] = "\n";
    $help[] = 'Options:'."\n";
    foreach ($options as $option) {
      $help[] = $option->printHelp($indent) . "\n";
    }
    return implode('', $help);
  }

}

/**
 * Base interface for parsable elements.
 *
 * @author Jordan Halterman <jordan.halterman@gmail.com>
 */
interface ParParseElementInterface {

  /**
   * Returns the element's command-line identifier.
   *
   * @return string
   *   The element's command-line identifier.
   */
  public function getName();

  /**
   * Sets the help text for the element.
   *
   * @param string $help
   *   The element's help text.
   *
   * @return ParParseElementInterface
   *   The called object.
   */
  public function setHelp($help);

  /**
   * Prints inline usage information for the sample command string.
   *
   * @return string
   *   Inline command usage.
   */
  public function printUsage();

  /**
   * Prints help text for the element.
   *
   * @param string $indent
   *   The indent string to use for the argument.
   *
   * @return string
   *   The help text for the element.
   */
  public function printHelp($indent = '');

}

/**
 * Interface for positional arguments.
 *
 * @author Jordan Halterman <jordan.halterman@gmail.com>
 */
interface ParParseArgumentInterface extends ParParseElementInterface {

  /**
   * Parses positional arguments from command-line arguments.
   *
   * @param array $args
   *   An array of command-line arguments.
   * @param bool $last_arg
   *   Indicates whether this is the last positional argument. Defaults to FALSE.
   *
   * @return mixed
   *   The element value.
   */
  public function parse(array &$args, $last_arg = FALSE);

}

/**
 * Interface for optional arguments.
 *
 * @author Jordan Halterman <jordan.halterman@gmail.com>
 */
interface ParParseOptionInterface extends ParParseElementInterface {

  /**
   * Parses elements from command-line arguments.
   *
   * @param array $args
   *   An array of command-line arguments.
   *
   * @return mixed
   *   The element value.
   */
  public function parse(array &$args);

}

/**
 * Base class for all parsable command-line elements.
 *
 * @author Jordan Halterman <jordan.halterman@gmail.com>
 */
abstract class ParParseElement implements ParParseElementInterface {

  /**
   * Indicates unlimited arity.
   *
   * @var int
   */
  const ARITY_UNLIMITED = -1;

  /**
   * Constants representing the relationship between string names
   * and the datatypes supported by PHP's settype() function.
   */
  const DATATYPE_BOOLEAN = 'bool';
  const DATATYPE_INTEGER = 'int';
  const DATATYPE_FLOAT = 'float';
  const DATATYPE_STRING = 'string';
  const DATATYPE_ARRAY = 'array';
  const DATATYPE_OBJECT = 'object';
  const DATATYPE_NULL = 'null';

  /**
   * A list of available data types for settype().
   *
   * @var array
   */
  private static $dataTypes = array(
    ParParseElement::DATATYPE_BOOLEAN,
    ParParseElement::DATATYPE_INTEGER,
    ParParseElement::DATATYPE_FLOAT,
    ParParseElement::DATATYPE_STRING,
    ParParseElement::DATATYPE_ARRAY,
    ParParseElement::DATATYPE_OBJECT,
    ParParseElement::DATATYPE_NULL,
  );

  /**
   * The element's unique machine name.
   *
   * @var string
   */
  protected $name;

  /**
   * The number of command line arguments expected by this element.
   *
   * @var int
   */
  protected $arity = 1;

  /**
   * The minimum number of arguments expected by this option when present.
   *
   * @var int|null
   */
  protected $min = NULL;

  /**
   * A validation callback.
   *
   * @var string|null
   */
  protected $validator = NULL;

  /**
   * The argument's data type.
   *
   * @var string|null
   */
  protected $dataType = NULL;

  /**
   * The element's command-line help text.
   *
   * @var string
   */
  protected $helpText = '';

  /**
   * Indicates whether the element has an explicitly assigned default value.
   *
   * @var bool
   */
  protected $hasDefault = FALSE;

  /**
   * The argument's default value.
   *
   * Note that only the last argument in a set of arguments can have
   * a default value, otherwise an exception will be thrown by the parser.
   *
   * @var string|null
   */
  protected $defaultValue = NULL;

  /**
   * Constructor.
   *
   * @param string $name
   *   The element's unique machine-name.
   * @param array $options
   *   An associative array of additional element options.
   */
  public function __construct($name, array $options = array()) {
    $this->name = $name;
    foreach ($options as $option => $value) {
      $this->setOption($option, $value);
    }
  }

  /**
   * Magic method: Gets an arbitrary element option.
   */
  public function __get($option) {
    try {
      return $this->getOption($option);
    }
    catch (InvalidArgumentException $e) {
      // Do nothing. Allow the option to be improperly accessed, which
      // should throw an error anyways.
    }
  }

  /**
   * Gets an arbitrary element option.
   *
   * @param string $option
   *   The name of the option to return.
   *
   * @return mixed
   *   The option value.
   *
   * @throws InvalidArgumentException
   *   If the given argument does not exist.
   */
  public function getOption($option) {
    if (strpos($option, '_') !== FALSE) {
      $option = implode('', array_map('ucfirst', explode('_', $option)));
    }
    $method = 'get'. ucfirst($option);
    if (!method_exists($this, $method)) {
      throw new InvalidArgumentException('Invalid option '. $option .'.');
    }
    return $this->{$method}();
  }

  /**
   * Magic method: Sets an arbitrary element option.
   */
  public function __set($option, $value) {
    return $this->setOption($option, $value);
  }

  /**
   * Sets an arbitrary element option.
   *
   * Note that setting values is done through setter methods, so only
   * options that have unique setter methods can be accessed.
   *
   * @param string $option
   *   The option to set.
   * @param mixed $value
   *   The option value to set.
   *
   * @return ParParseElement
   *   The called object.
   */
  public function setOption($option, $value) {
    if (strpos($option, '_') !== FALSE) {
      $option = implode('', array_map('ucfirst', explode('_', $option)));
    }
    $method = 'set'. ucfirst($option);
    if (!method_exists($this, $method)) {
      throw new InvalidArgumentException('Invalid option '. $option .'.');
    }
    return $this->{$method}($value);
  }

  /**
   * Returns the element's machine name.
   *
   * @return string
   *   The element's machine name.
   */
  public function getName() {
    return $this->name;
  }

  /**
   * Sets the help text for the element.
   *
   * @param string $help
   *   The element's help text.
   *
   * @return ParParseElement
   *   The called object.
   */
  public function setHelp($help) {
    if (!is_string($help)) {
      throw new InvalidArgumentException('Invalid help text for element '. $this->name .'. Help text must be a string.');
    }
    $this->help = $help;
    return $this;
  }

  /**
   * Alias for ParParseElement::setHelp().
   *
   * @see ParParseElement::setHelp()
   */
  public function setHelpText($help) {
    return $this->setHelp($help);
  }

  /**
   * Sets the element's arity.
   *
   * @param int $arity
   *   The element's arity.
   *
   * @return ParParseElement
   *   The called object.
   */
  public function setArity($arity) {
    if (!is_numeric($arity)) {
      throw new InvalidArgumentException('Invalid arity '. $arity .'. Arity must be numeric.');
    }
    $this->arity = (int) $arity;
    return $this;
  }

  /**
   * Sets the minimum number of arguments expected by this option when present.
   *
   * @param int $min
   *   The minimum number of arguments expected by this option when present.
   *
   * @return ParParseElement
   *   The called object.
   */
  public function setMin($min) {
    if (!is_numeric($min)) {
      throw new InvalidArgumentException('Minimum argument must be numeric.');
    }
    else if ($this->arity > self::ARITY_UNLIMITED && $min > $this->arity) {
      throw new InvalidArgumentException('Minimum argument must be less than or equal to the option arity.');
    }
    $this->min = (int) $min;
    return $this;
  }

  /**
   * Sets the element's default value.
   *
   * Validate the default value. If the default value is an array then it
   * must match an element with an arity above 1. Also, the array length must
   * match the arity, so arity must be set before the default value. If the
   * default value is not an array then it will simply be applied to all
   * elements of the result.
   *
   * @param mixed $default
   *   The default value.
   *
   * @return ParParseElement
   *   The called object.
   */
  public function setDefault($default) {
    if (is_array($default) && ($this->arity > self::ARITY_UNLIMITED && count($default) != $this->arity)) {
      throw new InvalidArgumentException('Defaults of type Array must have a length matching the arity of the element.');
    }
    else if (is_array($default) && $this->arity == 1) {
      throw new InvalidArgumentException('Elements with an arity of one cannot have defaults of type Array.');
    }
    $this->hasDefault = TRUE;
    $this->defaultValue = $default;
    return $this;
  }

  /**
   * Sets the argument's data type.
   *
   * @param string $data_type
   *   The data type.
   *
   * @return ParParseArgument
   *   The called object.
   */
  public function setType($data_type) {
    if (!in_array($data_type, self::$dataTypes) && !class_exists($data_type)) {
      throw new InvalidArgumentException('Invalid data type '. $data_type .'. Data type must be a PHP type or available class.');
    }
    $this->dataType = $data_type;
    return $this;
  }

  /**
   * Applies the current data type to the given value.
   *
   * @param string $value
   *   The value to which to apply the data type.
   *
   * @return mixed
   *   The new value with the data type applied.
   */
  protected function applyDataType($value) {
    if (isset($this->dataType)) {
      if (in_array($this->dataType, self::$dataTypes)) {
        settype($value, $this->dataType);
      }
      else {
        if (!class_exists($this->dataType)) {
          throw new InvalidArgumentException('Invalid data type '. $this->dataType .'. Data type must be a PHP type or available class.');
        }
        $value = new $this->dataType($value);
      }
    }

    $result = $this->validate($value);
    if (!$result) {
      throw new ParParseInvalidArgumentException('Invalid argument(s) for '. $this->name .'.');
    }
    return $value;
  }

  /**
   * Sets the value validator.
   *
   * @param string $validator
   *   The validator callback.
   *
   * @return ParParseElement
   *   The called object.
   */
  public function setValidator($callback) {
    $this->validator = $callback;
    return $this;
  }

  /**
   * Validates the element argument using validation callbacks.
   *
   * @param mixed $value
   *   The argument value.
   *
   * @return bool
   *   Indicates whether the argument is valid.
   */
  protected function validate($value) {
    if (!isset($this->validator)) {
      return TRUE;
    }
    if (!is_callable($this->validator)) {
      throw new InvalidArgumentException('Invalid validator '. $this->validator .'.');
    }
    return call_user_func($this->validator, $value);
  }

}

/**
 * Represents a positional argument.
 *
 * Positional arguments, unlike other arguments, have no prefix ("-") and are located
 * purely by their position in the command line arguments array. While positional
 * arguments may have arities, only the last positional argument may have an unlimited
 * arity or a default value for logical reasons.
 *
 * @author Jordan Halterman <jordan.halterman@gmail.com>
 */
class ParParseArgument extends ParParseElement implements ParParseArgumentInterface {

  /**
   * Parses positional arguments from command-line arguments.
   *
   * @param array $args
   *   An array of command-line arguments. This argument is passed by reference.
   * @param bool $last_arg
   *   Indicates whether this is the last positional argument. This is relevant in
   *   that the last positional argument may have default values applied. Alternatively,
   *   it would be logically impossible to apply default values to positional arguments
   *   that are not the last argument due to the fact that one cannot programmatically
   *   determine which positional argument is missing.
   *
   * @return mixed
   *   The argument value.
   */
  public function parse(array &$args, $last_arg = FALSE) {
    $args = array_values($args);
    $num_args = count($args);

    // Get only valid positional arguments from the $args array. This means all the
    // positional arguments (not prefixed by "-") up until the first option/flag or
    // the end of the array, whichever comes first.
    $valid_args = array();
    foreach ($args as $arg) {
      if (strpos($arg, '-') === 0) {
        break;
      }
      $valid_args[] = $arg;
    }

    // Ensure the correct number of arguments even exist.
    // Default values can only be applied to the last positional argument definition.
    // If the argument's default value is not an array then that default value
    // will be applied to each argument up to the arity number. However, if the default
    // value is an array then it *must* have the same number of values as the arity in
    // order to work properly.
    $num_valid_args = count($valid_args);
    if (($num_valid_args === 0 || $num_valid_args < $this->arity) && (!$last_arg || !$this->hasDefault || (is_array($this->defaultValue) && count($this->defaultValue) < $this->arity))) {
      throw new ParParseMissingArgumentException('Missing argument '. $this->name .'. '. $this->arity .' argument(s) expected.');
    }
    else if (isset($this->min) && $num_valid_args < $this->min) {
      throw new ParParseMissingArgumentException('Missing argument '. $this->name .'. '. $this->name .' expects at least '. $this->min .' argument(s).');
    }

    switch ($this->arity) {
      // Arguments with an arity of 1.
      case 1:
        for ($i = 0; $i < $num_args; $i++) {
          if (strpos($args[$i], '-') === 0) {
            continue;
          }
          else {
            $value = $args[$i];
            unset($args[$i]);
            return $this->applyDataType($value);
          }
        }
        if (!isset($this->min) || $this->min < 1) {
          return $this->defaultValue;
        }
        else {
          throw new ParParseMissingArgumentException('Missing argument '. $this->name .'. '. $this->name .' expects at least '. $this->min .' argument(s).');
        }
      // Arguments with an arity of greater than one or unlimited.
      // Note that unlimited arguments should only occur at the
      // end of the command line arguments string.
      default:
        // Create an array of defaults values.
        $values = array();
        if (is_array($this->defaultValue)) {
          $values = $this->defaultValue;
        }
        else {
          for ($i = 0; $i < $this->arity; $i++) {
            $values[] = $this->defaultValue;
          }
        }

        $position = 0;
        for ($i = 0; $i < $num_args; $i++) {
          if (strpos($args[$i], '-') === 0) {
            continue;
          }
          else {
            $values[$position++] = $this->applyDataType($args[$i]);
            unset($args[$i]);
          }

          if ($this->arity !== self::ARITY_UNLIMITED && $position == $this->arity) {
            break;
          }
        }

        // For arguments that have an unlimited arity and a scalar default
        // value simply return the default if no arguments were specified.
        if (empty($values) && $this->hasDefault) {
          return $this->defaultValue;
        }
        return $values;
    }
  }

  /**
   * Returns inline usage information for the sample command string.
   *
   * @return string
   *   Inline command usage.
   */
  public function printUsage() {
    $usage = '';
    if (isset($this->min)) {
      for ($i = 0; $i < $this->min; $i++) {
        $usage .= ' <'. $this->name .'>';
      }
      if ($this->arity == self::ARITY_UNLIMITED) {
        $usage .= ' [<'. $this->name .'>...]';
      }
      else if ($this->arity > $this->min) {
        for ($i = $this->min; $i < $this->arity; $i++) {
          $usage .= ' [<'. $this->name .'>]';
        }
      }
    }
    else {
      if ($this->arity == self::ARITY_UNLIMITED) {
        $usage .= ' <'. $this->name .'> <'. $this->name .'> ...';
      }
      else {
        for ($i = 0; $i < $this->arity; $i++) {
          $usage .= ' <'. $this->name .'>';
        }
      }
    }
    return trim($usage);
  }

  /**
   * Returns help text for the argument.
   *
   * @param string $indent
   *   The base indent string for the help text.
   *
   * @return string
   *   A help text string.
   */
  public function printHelp($indent = '') {
    $output = $indent . $this->name;
    $left_len = strlen($output);
    for ($i = 0; $i < 40 - $left_len; $i++) {
      $output .= ' ';
    }
    return $output . $this->help;
  }

}

/**
 * Represents an option.
 *
 * Options are prefixed with either "--" or "-" depending on whether the
 * short or long option is being used. Options can be used as either boolean
 * flags - with a value being applied when the option is present - or define
 * a value using either the -<short> <value> or --<long>=<value> format.
 *
 * @author Jordan Halterman <jordan.halterman@gmail.com>
 */
class ParParseOption extends ParParseElement implements ParParseOptionInterface {

  /**
   * The option's short alias. Defaults to NULL.
   *
   * @var string|null
   */
  private $alias = NULL;

  /**
   * Indicates that the option is required.
   *
   * @var bool
   */
  private $required = FALSE;

  /**
   * Constructor.
   *
   * @param string $name
   *   The element's unique long machine-name.
   * @param string|null $alias
   *   The element's unique short alias.
   * @param array $options
   *   An associative array of additional option options.
   */
  public function __construct($name, $alias = NULL, array $options = array()) {
    $options += array('alias' => $alias);
    parent::__construct($name, $options);
  }

  /**
   * Parses options from command-line arguments.
   *
   * @param array $args
   *   An array of command line arguments. This argument is passed by reference.
   *
   * @return mixed
   *   The option value, parsed from command line arguments.
   *   May return the default value if the argument was not found.
   */
  public function parse(array &$args) {
    $num_args = count($args);
    if ($this->arity == 0) {
      return $this->parseFlag($args);
    }
    else {
      return $this->parseOption($args);
    }
  }

  /**
   * Parses command line arguments as a boolean flag.
   *
   * @param array $args
   *   An array of command line arguments. This argument is passed by reference.
   *
   * @return bool
   *   The flag value, parsed from command line arguments.
   */
  private function parseFlag(array &$args) {
    $num_args = count($args);
    for ($i = 0; $i < $num_args; $i++) {
      if (isset($this->name) && $args[$i] == '--'. $this->name) {
        unset($args[$i]);
        return $this->applyDataType(TRUE);
      }
      else if (isset($this->alias) && $args[$i] == '-'. $this->alias) {
        unset($args[$i]);
        return $this->applyDataType(TRUE);
      }
    }
    // If no explicit default has been assigned then return FALSE.
    if (!$this->hasDefault) {
      return FALSE;
    }
    return $this->defaultValue;
  }

  /**
   * Parses command line arguments for option with values.
   *
   * @param array $args
   *   An array of command line arguments. This argument is passed by reference.
   *
   * @return mixed
   *   The option value, parsed from command line arguments.
   */
  private function parseOption(array &$args) {
    $num_args = count($args);
    for ($i = 0; $i < $num_args; $i++) {
      if (isset($this->name) && $args[$i] == '--'. $this->name) {
        return $this->getValueFromNextArg($args, $i);
      }
      else if (isset($this->alias) && $args[$i] == '-'. $this->alias) {
        return $this->getValueFromNextArg($args, $i);
      }
      else if (isset($this->name) && strpos($args[$i], '--'. $this->name .'=') === 0) {
        return $this->getValueFromArg($args, $i, '--'. $this->name .'=');
      }
      else if (isset($this->alias) && strpos($args[$i], '-'. $this->alias) === 0 && strlen($args[$i]) > strlen($this->alias) + 1) {
        return $this->getValueFromArg($args, $i, '-'. $this->alias);
      }
    }
    if ($this->required) {
      throw new ParParseMissingArgumentException('Missing option '. $this->name .'. '. $this->name .' is required.');
    }
    return $this->defaultValue;
  }

  /**
   * Attempts to get the option value from the command. This is used
   * in cases where an equals sign was used for the option value.
   *
   * @param array $args
   *   An array of command line arguments. This argument is passed by reference.
   * @param int $position
   *   The position of this argument.
   * @param string $prefix
   *   The prefix to remove from the argument.
   *
   * @return mixed
   *   The processed argument.
   */
  private function getValueFromArg(array &$args, $position, $prefix) {
    $arg = $args[$position];
    unset($args[$position]);
    $value = substr($arg, strlen($prefix));
    $min = isset($this->min) ? $this->min : $this->arity;

    // If the arity is greater than one then return this as an array.
    if ($this->arity == self::ARITY_UNLIMITED || $this->arity > 1) {
      $values = array_map('trim', explode(',', trim($value, '"')));

      // Create an array of default values for multiple argument options.
      $defaults = array();
      if (is_array($this->defaultValue)) {
        $defaults = $this->defaultValue;
      }
      else if ($this->arity > 0) {
        for ($i = 0; $i < $this->arity; $i++) {
          $defaults[] = $this->defaultValue;
        }
      }

      // Loop through available values and apply data types.
      foreach ($values as $key => $value) {
        $values[$key] = $this->applyDataType($value);
      }

      // Ensure that the minimum number of arguments were provided.
      if (count($values) < $this->arity) {
        $values = array_values($values) + array_values($defaults);
        if (count($values) < $this->arity) {
          throw new ParParseMissingArgumentException($this->name .' expects '. $this->arity .' arguments.');
        }
        if (count($values) < $min) {
          throw new ParParseMissingArgumentException($this->name .' expects at least '. $min .' arguments.');
        }
      }
      return $values;
    }
    return $this->applyDataType($value);
  }

  /**
   * Attempts to get the option value from the next command line argument.
   *
   * @param array $args
   *   An array of command line arguments. This argument is passed by reference.
   * @param int $position
   *   The position of this option in arguments.
   *
   * @return mixed
   *   The processed option value.
   */
  private function getValueFromNextArg(array &$args, $position) {
    $next = $position+1;
    $min = isset($this->min) ? $this->min : $this->arity;

    // Create an array of default values for multiple argument options.
    $defaults = array();
    if (is_array($this->defaultValue)) {
      $defaults = $this->defaultValue;
    }
    else if ($this->arity > 1) {
      for ($i = 0; $i < $this->arity; $i++) {
        $defaults[] = $this->defaultValue;
      }
    }

    // If this option has an arity of 1 then we return a single value
    // (rather than an array of values).
    if ($this->arity == 1) {
      if (!isset($args[$next]) || strpos($args[$next], '-') === 0) {
        if ($min > 0) {
          throw new ParParseMissingArgumentException($this->name .' expects one argument.');
        }
        return $this->defaultValue;
      }
      else {
        $value = $args[$next];
        unset($args[$position], $args[$next]);
        return $this->applyDataType($value);
      }
    }
    // Otherwise, if this argument has unlimited arity then get all valid
    // arguments up to the next option.
    else if ($this->arity == self::ARITY_UNLIMITED) {
      $values = array();
      for ($i = $next, $index = 0, $num_args = count($args); $i < $num_args; $i++) {
        if (strpos($args[$i], '-') !== 0) {
          $values[$index++] = $this->applyDataType($args[$i]);
          unset($args[$i]);
        }
        else {
          break;
        }
      }

      if (count($values) < $min) {
        throw new ParParseMissingArgumentException($this->name .' expects at least '. $min .' arguments.');
      }
      if (count($values) < count($defaults)) {
        $values = array_values($values) + array_values($defaults);
      }
      unset($args[$position]);
      return $values;
    }
    // Otherwise, this argument has a specified arity that is greater than
    // one. Get all values up to the next argument or the arity is reached.
    else {
      $values = array();
      for ($i = $next; $i < $next + $this->arity; $i++) {
        if (!isset($args[$i]) || strpos($args[$i], '-') === 0) {
          break;
        }
        else {
          $values[] = $this->applyDataType($args[$i]);
          unset($args[$i]);
        }
      }

      // If the number of values given were less than the arity then try to
      // apply default values.
      if (count($values) < $this->arity) {
        if (count($values) < $min) {
          throw new ParParseMissingArgumentException($this->name .' expects at least '. $min .' arguments.');
        }
        $values = array_values($values) + array_values($defaults);
        if (count($values) < $this->arity) {
          throw new ParParseMissingArgumentException($this->name .' expects '. $this->arity .' arguments.');
        }
      }
      unset($args[$position]);
      return $values;
    }
  }

  /**
   * Sets the option's short name.
   *
   * @param string $alias
   *   The option's short name. If no name is given then the option will be
   *   assumed to have no short name.
   *
   * @return ParParseOption
   *   The called object.
   */
  public function setAlias($alias = NULL) {
    if (!isset($alias)) {
      $this->alias = NULL;
      return $this;
    }
    if (!is_string($alias)) {
      throw new InvalidArgumentException('Invalid short alias. Short alias must be a string.');
    }
    if (strpos($alias, '-') === 0) {
      $this->alias = substr($alias, 1);
    }
    else {
      $this->alias = $alias;
    }
    return $this;
  }

  /**
   * Sets the option's data type.
   *
   * If the data type is set to 'bool' then we assume that this option
   * is a boolean flag and set its arity to zero.
   *
   * @param string $data_type
   *   The data type.
   *
   * @return ParParseOption
   *   The called object.
   */
  public function setType($data_type) {
    if ($data_type == self::DATATYPE_BOOLEAN) {
      $this->arity = 0;
    }
    return parent::setType($data_type);
  }

  /**
   * Sets the option's arity.
   *
   * Similar to when data types are set, if the arity is set to zero
   * then we set the data type to boolean.
   *
   * @param int $arity
   *   The element's arity.
   *
   * @return ParParseOption
   *   The called object.
   */
  public function setArity($arity) {
    parent::setArity($arity);
    if ($this->arity == 0) {
      $this->dataType = self::DATATYPE_BOOLEAN;
    }
    return $this;
  }

  /**
   * Sets the required status of the option.
   *
   * @param bool $required
   *   Indicates whether the option is required.
   *
   * @return ParParseOption
   *   The called object.
   */
  public function setRequired($required) {
    if (!is_bool($required)) {
      throw new InvalidArgumentException('Required value must be a boolean.');
    }
    if ($this->arity == 0 && $required) {
      throw new InvalidArgumentException('Boolean flags cannot be required.');
    }
    $this->required = $required;
    return $this;
  }

  /**
   * Returns inline usage information for the sample command string.
   *
   * @return string
   *   Inline command usage.
   */
  public function printUsage() {
    $usage = '';
    if (!$this->required) {
      $usage .= '[';
    }
    if ($this->alias) {
      $usage .= '-'. $this->alias;
    }
    else {
      $usage .= '--'. $this->name;
      if ($this->arity == 1) {
        if (!isset($this->min) || $this->min == 1) {
          $usage .= '=<'. $this->name .'>';
        }
        else {
          $usage .= '=[<'. $this->name .'>]';
        }
        if (!$this->required) {
          return $usage . ']';
        }
        return $usage;
      }
    }

    if (isset($this->min)) {
      for ($i = 0; $i < $this->min; $i++) {
        $usage .= ' <'. $this->name .'>';
      }
      if ($this->arity == self::ARITY_UNLIMITED) {
        $usage .= ' [<'. $this->name .'>...]';
      }
      else if ($this->arity > $this->min) {
        for ($i = $this->min; $i < $this->arity; $i++) {
          $usage .= ' [<'. $this->name .'>]';
        }
      }
    }
    else {
      if ($this->arity == self::ARITY_UNLIMITED) {
        $usage .= ' <'. $this->name .'> <'. $this->name .'> ...';
      }
      else {
        for ($i = 0; $i < $this->arity; $i++) {
          $usage .= ' <'. $this->name .'>';
        }
      }
    }
    if (!$this->required) {
      $usage .= ']';
    }
    return $usage;
  }

  /**
   * Returns help text for the option.
   */
  public function printHelp($indent = '') {
    $output = $indent;
    if ($this->name) {
      $output .= '--'. $this->name;
      if ($this->alias) {
        $output .= ' | -'. $this->alias;
      }
    }
    else if ($this->alias) {
      $output .= '-'. $this->alias;
    }

    $left_len = strlen($output);
    for ($i = 0; $i < 40 - $left_len; $i++) {
      $output .= ' ';
    }
    return $output . $this->help;
  }

}

/**
 * Parsed arguments results.
 *
 * @author Jordan Halterman <jordan.halterman@gmail.com>
 */
class ParParseResult {

  /**
   * Constructor.
   *
   * @param array $results
   *   An associative array of parsed results.
   */
  public function __construct(array $results) {
    $this->results = $results;
  }

  /**
   * Magic method: Gets a result value.
   */
  public function __get($name) {
    try {
      return $this->get($name);
    }
    catch (ParParseException $e) {
      // Do nothing if the result doesn't exist.
    }
  }

  /**
   * Gets a parser result.
   *
   * @param string $name
   *   The name of the element whose result to return.
   *
   * @return mixed
   *   The element result.
   */
  public function get($name) {
    if (isset($this->results[$name])) {
      return $this->results[$name];
    }
    throw new ParParseException('No result for '. $name .' exist.');
  }

}

/**
 * Base ParParse exception.
 *
 * @author Jordan Halterman <jordan.halterman@gmail.com>
 */
class ParParseException extends Exception {}

/**
 * Invalid argument exception.
 *
 * @author Jordan Halterman <jordan.halterman@gmail.com>
 */
class ParParseInvalidArgumentException extends ParParseException {}

/**
 * Missing argument exception.
 *
 * @author Jordan Halterman <jordan.halterman@gmail.com>
 */
class ParParseMissingArgumentException extends ParParseException {}
