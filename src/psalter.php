<?php
require_once('command_functions.php');

use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Config;
use Psalm\IssueBuffer;
use Psalm\Progress\DebugProgress;
use Psalm\Progress\DefaultProgress;

// show all errors
error_reporting(-1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('memory_limit', '4096M');

gc_collect_cycles();
gc_disable();

$args = array_slice($argv, 1);

$valid_short_options = ['f:', 'm', 'h', 'r:'];
$valid_long_options = [
    'help', 'debug', 'debug-by-line', 'config:', 'file:', 'root:',
    'plugin:', 'issues:', 'list-supported-issues', 'php-version:', 'dry-run', 'safe-types',
    'find-unused-code', 'threads:', 'codeowner:',
    'allow-backwards-incompatible-changes:',
];

// get options from command line
$options = getopt(implode('', $valid_short_options), $valid_long_options);

array_map(
    /**
     * @param string $arg
     *
     * @return void
     */
    function ($arg) use ($valid_long_options, $valid_short_options) {
        if (substr($arg, 0, 2) === '--' && $arg !== '--') {
            $arg_name = preg_replace('/=.*$/', '', substr($arg, 2));

            if ($arg_name === 'alter') {
                // valid option for psalm, ignored by psalter
                return;
            }

            if (!in_array($arg_name, $valid_long_options)
                && !in_array($arg_name . ':', $valid_long_options)
                && !in_array($arg_name . '::', $valid_long_options)
            ) {
                fwrite(
                    STDERR,
                    'Unrecognised argument "--' . $arg_name . '"' . PHP_EOL
                    . 'Type --help to see a list of supported arguments'. PHP_EOL
                );
                exit(1);
            }
        } elseif (substr($arg, 0, 2) === '-' && $arg !== '-' && $arg !== '--') {
            $arg_name = preg_replace('/=.*$/', '', substr($arg, 1));

            if (!in_array($arg_name, $valid_short_options) && !in_array($arg_name . ':', $valid_short_options)) {
                fwrite(
                    STDERR,
                    'Unrecognised argument "-' . $arg_name . '"' . PHP_EOL
                    . 'Type --help to see a list of supported arguments'. PHP_EOL
                );
                exit(1);
            }
        }
    },
    $args
);

if (array_key_exists('help', $options)) {
    $options['h'] = false;
}

if (array_key_exists('monochrome', $options)) {
    $options['m'] = false;
}

if (isset($options['config'])) {
    $options['c'] = $options['config'];
}

if (isset($options['c']) && is_array($options['c'])) {
    die('Too many config files provided' . PHP_EOL);
}

if (array_key_exists('h', $options)) {
    echo <<< HELP
Usage:
    psalter [options] [file...]

Options:
    -h, --help
        Display this help message

    --debug, --debug-by-line
        Debug information

    -c, --config=psalm.xml
        Path to a psalm.xml configuration file. Run psalm --init to create one.

    -m, --monochrome
        Enable monochrome output

    -r, --root
        If running Psalm globally you'll need to specify a project root. Defaults to cwd

    --plugin=PATH
        Executes a plugin, an alternative to using the Psalm config

    --dry-run
        Shows a diff of all the changes, without making them

    --safe-types
        Only update PHP types when the new type information comes from other PHP types,
        as opposed to type information that just comes from docblocks

    --php-version=PHP_MAJOR_VERSION.PHP_MINOR_VERSION

    --issues=IssueType1,IssueType2
        If any issues can be fixed automatically, Psalm will update the codebase. To fix as many issues as possible,
        use --issues=all

     --list-supported-issues
        Display the list of issues that psalter knows how to fix

    --find-unused-code
        Include unused code as a candidate for removal

    --threads=INT
        If greater than one, Psalm will run analysis on multiple threads, speeding things up.

    --codeowner=[codeowner]
        You can specify a GitHub code ownership group, and only that owner's code will be updated.

    --allow-backwards-incompatible-changes=BOOL
        Allow Psalm modify method signatures that could break code outside the project. Defaults to true.

HELP;

    exit;
}

if (!isset($options['issues']) &&
    !isset($options['list-supported-issues']) &&
    (!isset($options['plugin']) || $options['plugin'] === false)
) {
    fwrite(STDERR, 'Please specify the issues you want to fix with --issues=IssueOne,IssueTwo or --issues=all, ' .
        'or provide a plugin that has its own manipulations with --plugin=path/to/plugin.php' . PHP_EOL);
    exit(1);
}

if (isset($options['root'])) {
    $options['r'] = $options['root'];
}

$current_dir = (string)getcwd() . DIRECTORY_SEPARATOR;

if (isset($options['r']) && is_string($options['r'])) {
    $root_path = realpath($options['r']);

    if (!$root_path) {
        die('Could not locate root directory ' . $current_dir . DIRECTORY_SEPARATOR . $options['r'] . PHP_EOL);
    }

    $current_dir = $root_path . DIRECTORY_SEPARATOR;
}

$vendor_dir = getVendorDir($current_dir);

$first_autoloader = requireAutoloaders($current_dir, isset($options['r']), $vendor_dir);

// If XDebug is enabled, restart without it
(new \Composer\XdebugHandler\XdebugHandler('PSALTER'))->check();

$paths_to_check = getPathsToCheck(isset($options['f']) ? $options['f'] : null);

$path_to_config = isset($options['c']) && is_string($options['c']) ? realpath($options['c']) : null;

if ($path_to_config === false) {
    /** @psalm-suppress InvalidCast */
    die('Could not resolve path to config ' . (string)$options['c'] . PHP_EOL);
}

// initialise custom config, if passed
if ($path_to_config) {
    $config = Config::loadFromXMLFile($path_to_config, $current_dir);
} else {
    $config = Config::getConfigForPath($current_dir, $current_dir, ProjectAnalyzer::TYPE_CONSOLE);
}

$config->setComposerClassLoader($first_autoloader);

$threads = isset($options['threads']) ? (int)$options['threads'] : 1;

$providers = new Psalm\Internal\Provider\Providers(
    new Psalm\Internal\Provider\FileProvider(),
    new Psalm\Internal\Provider\ParserCacheProvider($config),
    new Psalm\Internal\Provider\FileStorageCacheProvider($config),
    new Psalm\Internal\Provider\ClassLikeStorageCacheProvider($config)
);

if (array_key_exists('list-supported-issues', $options)) {
    echo implode(',', ProjectAnalyzer::getSupportedIssuesToFix()) . PHP_EOL;
    exit();
}

$debug = array_key_exists('debug', $options);
$progress = $debug
    ? new DebugProgress()
    : new DefaultProgress();

$project_analyzer = new ProjectAnalyzer(
    $config,
    $providers,
    !array_key_exists('m', $options),
    false,
    ProjectAnalyzer::TYPE_CONSOLE,
    $threads,
    $progress
);

if (array_key_exists('debug-by-line', $options)) {
    $project_analyzer->debug_lines = true;
}

$config->visitComposerAutoloadFiles($project_analyzer);

if (array_key_exists('issues', $options)) {
    if (!is_string($options['issues']) || !$options['issues']) {
        die('Expecting a comma-separated list of issues' . PHP_EOL);
    }

    $issues = explode(',', $options['issues']);

    $keyed_issues = [];

    foreach ($issues as $issue) {
        $keyed_issues[$issue] = true;
    }
} else {
    $keyed_issues = [];
}

if (isset($options['php-version'])) {
    if (!is_string($options['php-version'])) {
        die('Expecting a version number in the format x.y' . PHP_EOL);
    }

    $project_analyzer->setPhpVersion($options['php-version']);
}

if (isset($options['codeowner'])) {
    if (file_exists('CODEOWNERS')) {
        $codeowners_file_path = realpath('CODEOWNERS');
    } elseif (file_exists('.github/CODEOWNERS')) {
        $codeowners_file_path = realpath('.github/CODEOWNERS');
    } elseif (file_exists('docs/CODEOWNERS')) {
        $codeowners_file_path = realpath('docs/CODEOWNERS');
    } else {
        die('Cannot use --codeowner without a CODEOWNERS file' . PHP_EOL);
    }

    $codeowners_file = file_get_contents($codeowners_file_path);

    $codeowner_lines = array_map(
        function (string $line) : array {
            $line_parts = preg_split('/\s+/', $line);

            $file_selector = substr(array_shift($line_parts), 1);
            return [$file_selector, $line_parts];
        },
        array_filter(
            explode("\n", $codeowners_file),
            function (string $line) : bool {
                $line = trim($line);

                // currently we don’t match wildcard files or files that could appear anywhere
                // in the repo
                return $line && $line[0] === '/' && strpos($line, '*') === false;
            }
        )
    );

    $codeowner_files = [];

    foreach ($codeowner_lines as list($path, $owners)) {
        if (!file_exists($path)) {
            continue;
        }

        foreach ($owners as $i => $owner) {
            $owners[$i] = strtolower($owner);
        }

        if (!is_dir($path)) {
            if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
                $codeowner_files[$path] = $owners;
            }
        } else {
            foreach ($providers->file_provider->getFilesInDir($path, ['php']) as $php_file_path) {
                $codeowner_files[$php_file_path] = $owners;
            }
        }
    }

    if (!$codeowner_files) {
        die('Could not find any available entries in CODEOWNERS' . PHP_EOL);
    }

    $desired_codeowners = is_array($options['codeowner']) ? $options['codeowner'] : [$options['codeowner']];

    /** @psalm-suppress MixedAssignment */
    foreach ($desired_codeowners as $desired_codeowner) {
        if (!is_string($desired_codeowner)) {
            die('Invalid --codeowner ' . (string)$desired_codeowner . PHP_EOL);
        }

        if ($desired_codeowner[0] !== '@') {
            die('--codeowner option must start with @' . PHP_EOL);
        }

        $matched_file = false;

        foreach ($codeowner_files as $file_path => $owners) {
            if (in_array(strtolower($desired_codeowner), $owners)) {
                $paths_to_check[] = $file_path;
                $matched_file = true;
            }
        }

        if (!$matched_file) {
            die('User/group ' . $desired_codeowner . ' does not own any PHP files' . PHP_EOL);
        }
    }
}

if (isset($options['allow-backwards-incompatible-changes'])) {
    $allow_backwards_incompatible_changes = filter_var(
        $options['allow-backwards-incompatible-changes'],
        FILTER_VALIDATE_BOOLEAN,
        ['flags' => FILTER_NULL_ON_FAILURE]
    );

    if ($allow_backwards_incompatible_changes === null) {
        die('--allow-backwards-incompatible-changes expectes a boolean value [true|false|1|0]' . PHP_EOL);
    }

    $project_analyzer->getCodebase()->allow_backwards_incompatible_changes = $allow_backwards_incompatible_changes;
}

$plugins = [];

if (isset($options['plugin'])) {
    $plugins = $options['plugin'];

    if (!is_array($plugins)) {
        $plugins = [$plugins];
    }
}

/** @var string $plugin_path */
foreach ($plugins as $plugin_path) {
    Config::getInstance()->addPluginPath($current_dir . $plugin_path);
}

$find_unused_code = array_key_exists('find-unused-code', $options);

if ($config->find_unused_code) {
    $find_unused_code = true;
}

foreach ($keyed_issues as $issue_name => $_) {
    // MissingParamType requires the scanning of all files to inform possible params
    if (strpos($issue_name, 'Unused') !== false || $issue_name === 'MissingParamType' || $issue_name === 'all') {
        $find_unused_code = true;
    }
}

if ($find_unused_code) {
    $project_analyzer->getCodebase()->reportUnusedCode();
}

$project_analyzer->alterCodeAfterCompletion(
    array_key_exists('dry-run', $options),
    array_key_exists('safe-types', $options)
);

if ($keyed_issues === ['all' => true]) {
    $project_analyzer->setAllIssuesToFix();
} else {
    try {
        $project_analyzer->setIssuesToFix($keyed_issues);
    } catch (\Psalm\Exception\UnsupportedIssueToFixException $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

$start_time = microtime(true);

if ($paths_to_check === null || count($paths_to_check) > 1 || $find_unused_code) {
    if ($paths_to_check) {
        $files_to_update = [];

        foreach ($paths_to_check as $path_to_check) {
            if (!is_dir($path_to_check)) {
                $files_to_update[] = (string) realpath($path_to_check);
            } else {
                foreach ($providers->file_provider->getFilesInDir($path_to_check, ['php']) as $php_file_path) {
                    $files_to_update[] = $php_file_path;
                }
            }
        }

        $project_analyzer->getCodebase()->analyzer->setFilesToUpdate($files_to_update);
    }

    $project_analyzer->check($current_dir);
} elseif ($paths_to_check) {
    foreach ($paths_to_check as $path_to_check) {
        if (is_dir($path_to_check)) {
            $project_analyzer->checkDir($path_to_check);
        } else {
            $project_analyzer->checkFile($path_to_check);
        }
    }
}

IssueBuffer::finish($project_analyzer, false, $start_time);
