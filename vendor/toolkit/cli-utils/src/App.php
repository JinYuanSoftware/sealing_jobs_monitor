<?php declare(strict_types=1);
/**
 * This file is part of toolkit/cli-utils.
 *
 * @link     https://github.com/php-toolkit/cli-utils
 * @author   https://github.com/inhere
 * @license  MIT
 */

namespace Toolkit\Cli;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use function array_merge;
use function array_shift;
use function array_values;
use function basename;
use function class_exists;
use function function_exists;
use function getcwd;
use function implode;
use function is_array;
use function is_object;
use function is_string;
use function ksort;
use function method_exists;
use function rtrim;
use function str_pad;
use function strlen;
use function strpos;
use function strtr;
use function trim;
use function ucfirst;

/**
 * Class App - A lite CLI Application
 *
 * @package Inhere\Console
 */
class App
{
    /** @var self */
    public static $global;

    private const COMMAND_CONFIG = [
        'desc'  => '',
        'usage' => '',
        'help'  => '',
    ];

    /** @var string Current dir */
    private $pwd;

    /**
     * @var array
     */
    protected $params = [
        'name'    => 'My application',
        'desc'    => 'My command line application',
        'version' => '0.2.1'
    ];

    /**
     * @var array Parsed from `arg0 name=val var2=val2`
     */
    private $args;

    /**
     * @var array Parsed from `--name=val --var2=val2 -d`
     */
    private $opts;

    /**
     * @var string
     */
    private $script;

    /**
     * @var string
     */
    private $command = '';

    /**
     * @var array User add commands
     */
    private $commands = [];

    /**
     * @var array Command messages for the commands
     */
    private $messages = [];

    /**
     * @var int
     */
    private $keyWidth = 12;

    /**
     * @return static
     */
    public static function global(): self
    {
        if (!self::$global) {
            throw new RuntimeException('please create global app by new App()');
        }

        return self::$global;
    }

    /**
     * Class constructor.
     *
     * @param array      $config
     * @param array|null $argv
     */
    public function __construct(array $config = [], array $argv = null)
    {
        // save self
        if (!self::$global) {
            self::$global = $this;
        }

        // get current dir
        $this->pwd = (string)getcwd();

        // parse cli argv
        $argv = $argv ?? $_SERVER['argv'];
        if ($config) {
            $this->setParams($config);
        }

        // get script file
        $this->script = array_shift($argv);

        // parse flags
        [
            $this->args,
            $this->opts
        ] = Flags::parseArgv(array_values($argv), ['mergeOpts' => true]);
    }

    /**
     * @param bool $exit
     *
     * @throws InvalidArgumentException
     */
    public function run(bool $exit = true): void
    {
        $this->findCommand();

        $this->dispatch($exit);
    }

    /**
     * find command name. it is first argument.
     */
    protected function findCommand(): void
    {
        if (!isset($this->args[0])) {
            return;
        }

        $newArgs = [];
        foreach ($this->args as $key => $value) {
            if ($key === 0) {
                $this->command = trim($value);
            } elseif (is_int($key)) {
                $newArgs[] = $value;
            } else {
                $newArgs[$key] = $value;
            }
        }

        $this->args = $newArgs;
    }

    /**
     * @param bool $exit
     *
     * @throws InvalidArgumentException
     */
    public function dispatch(bool $exit = true): void
    {
        $status = $this->doHandle();

        if ($exit) {
            $this->stop($status);
        }
    }

    /**
     * @return int
     */
    protected function doHandle(): int
    {
        if (!$command = $this->command) {
            $this->displayHelp();
            return 0;
        }

        if (!isset($this->commands[$command])) {
            $this->displayHelp("The command '$command' is not exists!");
            return 0;
        }

        if (isset($this->opts['h']) || isset($this->opts['help'])) {
            $this->displayCommandHelp($command);
            return 0;
        }

        try {
            $status = $this->runHandler($command, $this->commands[$command]);
        } catch (Throwable $e) {
            $status = $this->handleException($e);
        }

        return (int)$status;
    }

    /**
     * @param int $code
     */
    public function stop(int $code = 0): void
    {
        exit($code);
    }

    /**
     * @param string $command
     * @param mixed  $handler
     *
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function runHandler(string $command, $handler)
    {
        if (is_string($handler)) {
            // function name
            if (function_exists($handler)) {
                return $handler($this);
            }

            if (class_exists($handler)) {
                $handler = new $handler;

                // $handler->execute()
                if (method_exists($handler, 'execute')) {
                    return $handler->execute($this);
                }
            }
        }

        // a \Closure OR $handler->__invoke()
        if (is_object($handler) && method_exists($handler, '__invoke')) {
            return $handler($this);
        }

        throw new RuntimeException("Invalid handler of the command: $command");
    }

    /**
     * @param Throwable $e
     *
     * @return int
     */
    protected function handleException(Throwable $e): int
    {
        if ($e instanceof InvalidArgumentException) {
            Color::println('ERROR: ' . $e->getMessage(), 'error');
            return 0;
        }

        $code = $e->getCode() !== 0 ? $e->getCode() : -1;
        $eTpl = "Exception(%d): %s\nFile: %s(Line %d)\nTrace:\n%s\n";

        // print exception message
        printf($eTpl, $code, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());

        return $code;
    }

    /**
     * @param callable $handler
     * @param array    $config
     */
    public function addObject(callable $handler, array $config = []): void
    {
        if (is_object($handler) && method_exists($handler, '__invoke')) {
            // has config method
            if (method_exists($handler, 'getHelpConfig')) {
                $config = $handler->getHelpConfig();
            }

            $this->addByConfig($handler, $config);
            return;
        }

        throw new InvalidArgumentException('Command handler must be an object and has method: __invoke');
    }

    /**
     * @param callable $handler
     * @param array    $config
     */
    public function addByConfig(callable $handler, array $config): void
    {
        if (empty($config['name']) || !$handler) {
            throw new InvalidArgumentException('Invalid arguments for add command');
        }

        $this->addCommand($config['name'], $handler, $config);
    }

    /**
     * @param string            $command
     * @param callable          $handler
     * @param null|array|string $config
     */
    public function add(string $command, callable $handler, $config = null): void
    {
        $this->addCommand($command, $handler, $config);
    }

    /**
     * @param string            $command
     * @param callable          $handler
     * @param null|array|string $config
     */
    public function addCommand(string $command, callable $handler, $config = null): void
    {
        if (!$command || !$handler) {
            throw new InvalidArgumentException('Invalid arguments for add command');
        }

        if (($len = strlen($command)) > $this->keyWidth) {
            $this->keyWidth = $len;
        }

        $this->commands[$command] = $handler;

        // no config
        if (!$config) {
            return;
        }

        if (is_string($config)) {
            $desc   = trim($config);
            $config = self::COMMAND_CONFIG;

            // append desc
            $config['desc'] = $desc;

            // save
            $this->messages[$command] = $config;
        } elseif (is_array($config)) {
            $this->messages[$command] = array_merge(self::COMMAND_CONFIG, $config);
        }
    }

    /**
     * @param array $commands
     *
     * @throws InvalidArgumentException
     */
    public function addCommands(array $commands): void
    {
        foreach ($commands as $command => $handler) {
            $conf = [];
            $name = is_string($command) ? $command : '';

            if (is_array($handler) && isset($handler['handler'])) {
                $conf = $handler;
                $name = $conf['name'] ?? $name;

                $handler = $conf['handler'];
                unset($conf['name'], $conf['handler']);
            }

            $this->addCommand($name, $handler, $conf);
        }
    }

    /****************************************************************************
     * helper methods
     ****************************************************************************/

    /**
     * @param string $err
     */
    public function displayHelp(string $err = ''): void
    {
        if ($err) {
            Cli::println("<red>ERROR</red>: $err\n");
        }

        // help
        $desc = ucfirst($this->params['desc']);
        if ($ver = $this->params['version']) {
            $desc .= "(<red>v$ver</red>)";
        }

        $script = $this->script;
        $usage  = "<cyan>$script COMMAND -h</cyan>";

        $help = "$desc\n\n<comment>Usage:</comment> $usage\n<comment>Commands:</comment>\n";
        $data = $this->messages;
        ksort($data);

        foreach ($data as $command => $item) {
            $command = str_pad($command, $this->keyWidth);

            $desc = $item['desc'] ? ucfirst($item['desc']) : 'No description for the command';
            $help .= "  <green>$command</green>   $desc\n";
        }

        $help .= "\nFor command usage please run: <cyan>$script COMMAND -h</cyan>";

        Cli::println($help);
    }

    /**
     * @param string $name
     */
    public function displayCommandHelp(string $name): void
    {
        $checkVar = false;
        $fullCmd  = $this->script . " $name";

        $config = $this->messages[$name] ?? [];
        $usage  = "$fullCmd [args ...] [--opts ...]";

        if (!$config) {
            $nodes = [
                'No description for the command',
                "<comment>Usage:</comment> \n  $usage"
            ];
        } else {
            $checkVar = true;
            $userHelp = rtrim($config['help'], "\n");

            $usage = $config['usage'] ?: $usage;
            $nodes = [
                ucfirst($config['desc']),
                "<comment>Usage:</comment> \n  $usage\n",
                $userHelp ? $userHelp . "\n" : ''
            ];
        }

        $help = implode("\n", $nodes);

        if ($checkVar && strpos($help, '{{')) {
            $help = strtr($help, [
                '{{command}}' => $name,
                '{{fullCmd}}' => $fullCmd,
                '{{workDir}}' => $this->pwd,
                '{{pwdDir}}'  => $this->pwd,
                '{{script}}'  => $this->script,
            ]);
        }

        Cli::println($help);
    }

    /**
     * @param string|int $name
     * @param mixed      $default
     *
     * @return mixed|null
     */
    public function getArg($name, $default = null)
    {
        return $this->args[$name] ?? $default;
    }

    /**
     * @param string|int $name
     * @param int        $default
     *
     * @return int
     */
    public function getIntArg($name, int $default = 0): int
    {
        return (int)$this->getArg($name, $default);
    }

    /**
     * @param string|int $name
     * @param string     $default
     *
     * @return string
     */
    public function getStrArg($name, string $default = ''): string
    {
        return (string)$this->getArg($name, $default);
    }

    /**
     * @param string $name
     * @param mixed  $default
     *
     * @return mixed|null
     */
    public function getOpt(string $name, $default = null)
    {
        return $this->opts[$name] ?? $default;
    }

    /**
     * @param string $name
     * @param int    $default
     *
     * @return int
     */
    public function getIntOpt(string $name, int $default = 0): int
    {
        return (int)$this->getOpt($name, $default);
    }

    /**
     * @param string $name
     * @param string $default
     *
     * @return string
     */
    public function getStrOpt(string $name, string $default = ''): string
    {
        return (string)$this->getOpt($name, $default);
    }

    /**
     * @param string $name
     * @param bool   $default
     *
     * @return bool
     */
    public function getBoolOpt(string $name, bool $default = false): bool
    {
        return (bool)$this->getOpt($name, $default);
    }

    /****************************************************************************
     * getter/setter methods
     ****************************************************************************/

    /**
     * @return array
     */
    public function getArgs(): array
    {
        return $this->args;
    }

    /**
     * @param array $args
     */
    public function setArgs(array $args): void
    {
        $this->args = $args;
    }

    /**
     * @return array
     */
    public function getOpts(): array
    {
        return $this->opts;
    }

    /**
     * @param array $opts
     */
    public function setOpts(array $opts): void
    {
        $this->opts = $opts;
    }

    /**
     * @return string
     */
    public function getScript(): string
    {
        return $this->script;
    }

    /**
     * @return string
     */
    public function getScriptName(): string
    {
        return basename($this->script);
    }

    /**
     * @param string $script
     */
    public function setScript(string $script): void
    {
        $this->script = $script;
    }

    /**
     * @return string
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * @param string $command
     */
    public function setCommand(string $command): void
    {
        $this->command = $command;
    }

    /**
     * @return array
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * @param array $commands
     */
    public function setCommands(array $commands): void
    {
        $this->commands = $commands;
    }

    /**
     * @return array
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @return int
     */
    public function getKeyWidth(): int
    {
        return $this->keyWidth;
    }

    /**
     * @param int $keyWidth
     */
    public function setKeyWidth(int $keyWidth): void
    {
        $this->keyWidth = $keyWidth > 1 ? $keyWidth : 12;
    }

    /**
     * @return string
     */
    public function getPwd(): string
    {
        return $this->pwd;
    }

    /**
     * @return array
     * @deprecated please use getParams();
     */
    public function getMetas(): array
    {
        return $this->getParams();
    }

    /**
     * @param array $params
     *
     * @deprecated please use setParams()
     */
    public function setMetas(array $params): void
    {
        $this->setParams($params);
    }

    /**
     * @param string $key
     * @param null   $default
     *
     * @return mixed|string|null
     */
    public function getParam(string $key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * @param string $key
     * @param mixed  $val
     */
    public function setParam(string $key, $val): void
    {
        $this->params[$key] = $val;
    }

    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param array $params
     */
    public function setParams(array $params): void
    {
        $this->params = array_merge($this->params, $params);
    }
}
