<?php
namespace Psalm\Internal\Analyzer;

use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\Exception\UnsupportedIssueToFixException;
use Psalm\FileManipulation;
use Psalm\Internal\LanguageServer\{LanguageServer, ProtocolStreamReader, ProtocolStreamWriter};
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Internal\Provider\FileProvider;
use Psalm\Internal\Provider\FileReferenceProvider;
use Psalm\Internal\Provider\ParserCacheProvider;
use Psalm\Internal\Provider\Providers;
use Psalm\Issue\InvalidFalsableReturnType;
use Psalm\Issue\InvalidNullableReturnType;
use Psalm\Issue\InvalidReturnType;
use Psalm\Issue\LessSpecificReturnType;
use Psalm\Issue\MismatchingDocblockParamType;
use Psalm\Issue\MismatchingDocblockReturnType;
use Psalm\Issue\MissingClosureReturnType;
use Psalm\Issue\MissingParamType;
use Psalm\Issue\MissingReturnType;
use Psalm\Issue\PossiblyUndefinedGlobalVariable;
use Psalm\Issue\PossiblyUndefinedVariable;
use Psalm\Issue\PossiblyUnusedMethod;
use Psalm\Issue\PossiblyUnusedProperty;
use Psalm\Issue\UnusedMethod;
use Psalm\Issue\UnusedProperty;
use Psalm\Progress\Progress;
use Psalm\Progress\VoidProgress;
use Psalm\Type;
use Psalm\Issue\CodeIssue;

/**
 * @internal
 */
class ProjectAnalyzer
{
    /**
     * Cached config
     *
     * @var Config
     */
    private $config;

    /**
     * @var self
     */
    public static $instance;

    /**
     * An object representing everything we know about the code
     *
     * @var Codebase
     */
    private $codebase;

    /** @var FileProvider */
    private $file_provider;

    /** @var ClassLikeStorageProvider */
    private $classlike_storage_provider;

    /** @var ?ParserCacheProvider */
    private $parser_cache_provider;

    /** @var FileReferenceProvider */
    private $file_reference_provider;

    /**
     * Whether or not to use colors in error output
     *
     * @var bool
     */
    public $use_color;

    /**
     * Whether or not to show snippets in error output
     *
     * @var bool
     */
    public $show_snippet;

    /**
     * Whether or not to show informational messages
     *
     * @var bool
     */
    public $show_info;

    /**
     * @var string
     */
    public $output_format;

    /**
     * @var Progress
     */
    public $progress;

    /**
     * @var bool
     */
    public $debug_lines = false;

    /**
     * @var bool
     */
    public $show_issues = true;

    /** @var int */
    public $threads;

    /**
     * @var array<string,string>
     */
    public $reports = [];

    /**
     * @var array<string, bool>
     */
    private $issues_to_fix = [];

    /**
     * @var bool
     */
    public $dry_run = false;

    /**
     * @var bool
     */
    public $full_run = false;

    /**
     * @var bool
     */
    public $only_replace_php_types_with_non_docblock_types = false;

    /**
     * @var ?int
     */
    public $onchange_line_limit;

    /**
     * @var bool
     */
    public $provide_completion = false;

    /**
     * @var array<string,string>
     */
    private $project_files;

    /**
     * @var array<string, string>
     */
    private $to_refactor = [];

    const TYPE_COMPACT = 'compact';
    const TYPE_CONSOLE = 'console';
    const TYPE_PYLINT = 'pylint';
    const TYPE_JSON = 'json';
    const TYPE_JSON_SUMMARY = 'json-summary';
    const TYPE_EMACS = 'emacs';
    const TYPE_XML = 'xml';
    const TYPE_CHECKSTYLE = 'checkstyle';
    const TYPE_TEXT = 'text';

    const SUPPORTED_OUTPUT_TYPES = [
        self::TYPE_COMPACT,
        self::TYPE_CONSOLE,
        self::TYPE_PYLINT,
        self::TYPE_JSON,
        self::TYPE_JSON_SUMMARY,
        self::TYPE_EMACS,
        self::TYPE_XML,
        self::TYPE_CHECKSTYLE,
        self::TYPE_TEXT,
    ];

    /**
     * @var array<int, class-string<CodeIssue>>
     */
    const SUPPORTED_ISSUES_TO_FIX = [
        InvalidFalsableReturnType::class,
        InvalidNullableReturnType::class,
        InvalidReturnType::class,
        LessSpecificReturnType::class,
        MismatchingDocblockParamType::class,
        MismatchingDocblockReturnType::class,
        MissingClosureReturnType::class,
        MissingParamType::class,
        MissingReturnType::class,
        PossiblyUndefinedGlobalVariable::class,
        PossiblyUndefinedVariable::class,
        PossiblyUnusedMethod::class,
        PossiblyUnusedProperty::class,
        UnusedMethod::class,
        UnusedProperty::class,
    ];

    /**
     * @param bool          $use_color
     * @param bool          $show_info
     * @param string        $output_format
     * @param int           $threads
     * @param string        $reports
     * @param bool          $show_snippet
     */
    public function __construct(
        Config $config,
        Providers $providers,
        $use_color = true,
        $show_info = true,
        $output_format = self::TYPE_CONSOLE,
        $threads = 1,
        Progress $progress = null,
        $reports = null,
        $show_snippet = true
    ) {
        if ($progress === null) {
            $progress = new VoidProgress();
        }

        $this->parser_cache_provider = $providers->parser_cache_provider;
        $this->file_provider = $providers->file_provider;
        $this->classlike_storage_provider = $providers->classlike_storage_provider;
        $this->file_reference_provider = $providers->file_reference_provider;

        $this->use_color = $use_color;
        $this->show_info = $show_info;
        $this->progress = $progress;
        $this->threads = $threads;
        $this->config = $config;
        $this->show_snippet = $show_snippet;

        $this->codebase = new Codebase(
            $config,
            $providers,
            $progress
        );

        if (!in_array($output_format, self::SUPPORTED_OUTPUT_TYPES, true)) {
            throw new \UnexpectedValueException('Unrecognised output format ' . $output_format);
        }

        if ($reports) {
            $mapping = [
                'checkstyle.xml' => self::TYPE_CHECKSTYLE,
                'summary.json' => self::TYPE_JSON_SUMMARY,
                '.xml' => self::TYPE_XML,
                '.json' => self::TYPE_JSON,
                '.txt' => self::TYPE_TEXT,
                '.emacs' => self::TYPE_EMACS,
                '.pylint' => self::TYPE_PYLINT,
            ];
            foreach ($mapping as $extension => $type) {
                if (substr($reports, -strlen($extension)) === $extension) {
                    $this->reports[$type] = $reports;
                    break;
                }
            }
            if (empty($this->reports)) {
                throw new \UnexpectedValueException('Unrecognised report format ' . $reports);
            }
        }

        $project_files = [];

        foreach ($this->config->getProjectDirectories() as $dir_name) {
            $file_extensions = $this->config->getFileExtensions();

            $file_paths = $this->file_provider->getFilesInDir($dir_name, $file_extensions);

            foreach ($file_paths as $file_path) {
                if ($this->config->isInProjectDirs($file_path)) {
                    $project_files[$file_path] = $file_path;
                }
            }
        }

        foreach ($this->config->getProjectFiles() as $file_path) {
            $project_files[$file_path] = $file_path;
        }

        $this->project_files = $project_files;

        $this->output_format = $output_format;
        self::$instance = $this;
    }

    /**
     * @param  string|null $address
     * @return void
     */
    public function server($address = '127.0.0.1:12345', bool $socket_server_mode = false)
    {
        $this->codebase->diff_methods = true;
        $this->file_reference_provider->loadReferenceCache();
        $this->codebase->enterServerMode();

        $cpu_count = self::getCpuCount();

        // let's not go crazy
        $usable_cpus = $cpu_count - 2;

        if ($usable_cpus > 1) {
            $this->threads = $usable_cpus;
        }

        $this->config->initializePlugins($this);

        foreach ($this->config->getProjectDirectories() as $dir_name) {
            $this->checkDirWithConfig($dir_name, $this->config);
        }

        $this->output_format = self::TYPE_JSON;

        @cli_set_process_title('Psalm PHP Language Server');

        if (!$socket_server_mode && $address) {
            // Connect to a TCP server
            $socket = stream_socket_client('tcp://' . $address, $errno, $errstr);
            if ($socket === false) {
                fwrite(STDERR, "Could not connect to language client. Error $errno\n$errstr");
                exit(1);
            }
            stream_set_blocking($socket, false);
            new LanguageServer(
                new ProtocolStreamReader($socket),
                new ProtocolStreamWriter($socket),
                $this
            );
            \Amp\Loop::run();
        } elseif ($socket_server_mode && $address) {
            // Run a TCP Server
            $tcpServer = stream_socket_server('tcp://' . $address, $errno, $errstr);
            if ($tcpServer === false) {
                fwrite(STDERR, "Could not listen on $address. Error $errno\n$errstr");
                exit(1);
            }
            fwrite(STDOUT, "Server listening on $address\n");
            if (!extension_loaded('pcntl')) {
                fwrite(STDERR, "PCNTL is not available. Only a single connection will be accepted\n");
            }
            while ($socket = stream_socket_accept($tcpServer, -1)) {
                fwrite(STDOUT, "Connection accepted\n");
                stream_set_blocking($socket, false);
                if (extension_loaded('pcntl')) {
                    // If PCNTL is available, fork a child process for the connection
                    // An exit notification will only terminate the child process
                    $pid = pcntl_fork();
                    if ($pid === -1) {
                        fwrite(STDERR, "Could not fork\n");
                        exit(1);
                    }

                    if ($pid === 0) {
                        // Child process
                        $reader = new ProtocolStreamReader($socket);
                        $reader->on(
                            'close',
                            /** @return void */
                            function () {
                                fwrite(STDOUT, "Connection closed\n");
                            }
                        );
                        new LanguageServer(
                            $reader,
                            new ProtocolStreamWriter($socket),
                            $this
                        );
                        // Just for safety
                        exit(0);
                    }
                } else {
                    // If PCNTL is not available, we only accept one connection.
                    // An exit notification will terminate the server
                    new LanguageServer(
                        new ProtocolStreamReader($socket),
                        new ProtocolStreamWriter($socket),
                        $this
                    );
                    \Amp\Loop::run();
                }
            }
        } else {
            // Use STDIO
            stream_set_blocking(STDIN, false);
            new LanguageServer(
                new ProtocolStreamReader(STDIN),
                new ProtocolStreamWriter(STDOUT),
                $this
            );
            \Amp\Loop::run();
        }
    }

    /**
     * @return self
     */
    public static function getInstance()
    {
        return self::$instance;
    }

    /**
     * @param  string $file_path
     *
     * @return bool
     */
    public function canReportIssues($file_path)
    {
        return isset($this->project_files[$file_path]);
    }

    /**
     * @param  string  $base_dir
     * @param  bool $is_diff
     *
     * @return void
     */
    public function check($base_dir, $is_diff = false)
    {
        $start_checks = (int)microtime(true);

        if (!$base_dir) {
            throw new \InvalidArgumentException('Cannot work with empty base_dir');
        }

        $diff_files = null;
        $deleted_files = null;

        $this->full_run = true;

        $reference_cache = $this->file_reference_provider->loadReferenceCache(true);

        if ($is_diff
            && $reference_cache
            && $this->parser_cache_provider
            && $this->parser_cache_provider->canDiffFiles()
        ) {
            $deleted_files = $this->file_reference_provider->getDeletedReferencedFiles();
            $diff_files = $deleted_files;

            foreach ($this->config->getProjectDirectories() as $dir_name) {
                $diff_files = array_merge($diff_files, $this->getDiffFilesInDir($dir_name, $this->config));
            }
        }

        $this->progress->startScanningFiles();

        if ($diff_files === null
            || $deleted_files === null
            || count($diff_files) > 200
            || $this->codebase->find_unused_code) {
            $this->codebase->scanner->addFilesToDeepScan($this->project_files);
        }

        if ($diff_files === null
            || $deleted_files === null
            || count($diff_files) > 200
        ) {
            $this->codebase->analyzer->addFiles($this->project_files);

            $this->config->initializePlugins($this);

            $this->codebase->scanFiles($this->threads);
        } else {
            $this->progress->debug(count($diff_files) . ' changed files: ' . "\n");
            $this->progress->debug('    ' . implode("\n    ", $diff_files) . "\n");

            if ($diff_files || $this->codebase->find_unused_code) {
                $file_list = $this->getReferencedFilesFromDiff($diff_files);

                // strip out deleted files
                $file_list = array_diff($file_list, $deleted_files);

                $this->checkDiffFilesWithConfig($this->config, $file_list);

                $this->config->initializePlugins($this);

                $this->codebase->scanFiles($this->threads);
            }
        }

        $this->config->visitStubFiles($this->codebase, $this->progress);

        $plugin_classes = $this->config->after_codebase_populated;

        if ($plugin_classes) {
            foreach ($plugin_classes as $plugin_fq_class_name) {
                $plugin_fq_class_name::afterCodebasePopulated($this->codebase);
            }
        }

        $this->progress->startAnalyzingFiles();

        $this->codebase->analyzer->analyzeFiles($this, $this->threads, $this->codebase->alter_code);

        if ($this->parser_cache_provider) {
            $removed_parser_files = $this->parser_cache_provider->deleteOldParserCaches(
                $is_diff ? $this->parser_cache_provider->getLastGoodRun() : $start_checks
            );

            if ($removed_parser_files) {
                $this->progress->debug('Removed ' . $removed_parser_files . ' old parser caches' . "\n");
            }

            if ($is_diff) {
                $this->parser_cache_provider->touchParserCaches($this->getAllFiles($this->config), $start_checks);
            }
        }
    }

    /**
     * @return void
     */
    public function checkClassReferences()
    {
        if (!$this->codebase->collect_references) {
            throw new \UnexpectedValueException('Should not be checking references');
        }

        $this->codebase->classlikes->checkClassReferences(
            $this->codebase->methods,
            $this->progress
        );
    }

    public function interpretRefactors() : void
    {
        if (!$this->codebase->alter_code) {
            throw new \UnexpectedValueException('Should not be checking references');
        }

        foreach ($this->to_refactor as $source => $destination) {
            $source_parts = explode('::', $source);
            $destination_parts = explode('::', $destination);

            if (count($source_parts) === 1 || count($destination_parts) === 1) {
                throw new \Psalm\Exception\RefactorException('Cannot yet refactor classes');
            }

            if ($this->codebase->methods->methodExists($source)) {
                if ($this->codebase->methods->methodExists($destination)) {
                    throw new \Psalm\Exception\RefactorException(
                        'Destination ' . $destination . ' already exists'
                    );
                } elseif (!$this->codebase->classlikes->classExists($destination_parts[0])) {
                    throw new \Psalm\Exception\RefactorException(
                        'Destination class ' . $destination_parts[0] . ' doesn’t exist'
                    );
                }

                if (strtolower($source_parts[0]) !== strtolower($destination_parts[0])) {
                    $source_storage = $this->codebase->methods->getStorage($source);

                    if (!$source_storage->is_static) {
                        throw new \Psalm\Exception\RefactorException(
                            'Cannot move non-static method ' . $source
                        );
                    }

                    $this->codebase->methods_to_move[strtolower($source)] = $destination;
                } else {
                    $this->codebase->methods_to_rename[strtolower($source)] = $destination_parts[1];
                }

                $this->codebase->call_transforms[strtolower($source) . '\((.*\))'] = $destination . '($1)';
                continue;
            }

            throw new \Psalm\Exception\RefactorException(
                'At present Psalm can only move static methods (attempted to move ' . $source . ')'
            );
        }
    }

    public function prepareMigration() : void
    {
        if (!$this->codebase->alter_code) {
            throw new \UnexpectedValueException('Should not be checking references');
        }

        $this->codebase->classlikes->moveMethods(
            $this->codebase->methods,
            $this->progress
        );
    }

    public function migrateCode() : void
    {
        if (!$this->codebase->alter_code) {
            throw new \UnexpectedValueException('Should not be checking references');
        }

        $migration_manipulations = \Psalm\Internal\FileManipulation\FileManipulationBuffer::getMigrationManipulations(
            $this->codebase->file_provider
        );

        if (!$migration_manipulations) {
            return;
        }

        foreach ($migration_manipulations as $file_path => $file_manipulations) {
            usort(
                $file_manipulations,
                /**
                 * @return int
                 */
                function (FileManipulation $a, FileManipulation $b) {
                    if ($a->start === $b->start) {
                        if ($b->end === $a->end) {
                            return $b->insertion_text > $a->insertion_text ? 1 : -1;
                        }

                        return $b->end > $a->end ? 1 : -1;
                    }

                    return $b->start > $a->start ? 1 : -1;
                }
            );

            $existing_contents = $this->codebase->file_provider->getContents($file_path);

            $pre_applied_manipulations = [];

            foreach ($file_manipulations as $manipulation) {
                if (isset($pre_applied_manipulations[$manipulation->getKey()])) {
                    continue;
                }

                $existing_contents
                    = substr($existing_contents, 0, $manipulation->start)
                        . $manipulation->insertion_text
                        . substr($existing_contents, $manipulation->end);

                $pre_applied_manipulations[$manipulation->getKey()] = true;
            }

            $this->codebase->file_provider->setContents($file_path, $existing_contents);
        }
    }

    /**
     * @param  string $symbol
     *
     * @return void
     */
    public function findReferencesTo($symbol)
    {
        $locations = $this->codebase->findReferencesToSymbol($symbol);

        foreach ($locations as $location) {
            $snippet = $location->getSnippet();

            $snippet_bounds = $location->getSnippetBounds();
            $selection_bounds = $location->getSelectionBounds();

            $selection_start = $selection_bounds[0] - $snippet_bounds[0];
            $selection_length = $selection_bounds[1] - $selection_bounds[0];

            echo $location->file_name . ':' . $location->getLineNumber() . "\n" .
                (
                    $this->use_color
                    ? substr($snippet, 0, $selection_start) .
                    "\e[97;42m" . substr($snippet, $selection_start, $selection_length) .
                    "\e[0m" . substr($snippet, $selection_length + $selection_start)
                    : $snippet
                ) . "\n" . "\n";
        }
    }

    /**
     * @param  string  $dir_name
     *
     * @return void
     */
    public function checkDir($dir_name)
    {
        $this->file_reference_provider->loadReferenceCache();

        $this->checkDirWithConfig($dir_name, $this->config, true);

        $this->progress->startScanningFiles();

        $this->config->initializePlugins($this);

        $this->codebase->scanFiles($this->threads);

        $this->config->visitStubFiles($this->codebase, $this->progress);

        $this->progress->startAnalyzingFiles();

        $this->codebase->analyzer->analyzeFiles($this, $this->threads, $this->codebase->alter_code);
    }

    /**
     * @param  string $dir_name
     * @param  Config $config
     * @param  bool   $allow_non_project_files
     *
     * @return void
     */
    private function checkDirWithConfig($dir_name, Config $config, $allow_non_project_files = false)
    {
        $file_extensions = $config->getFileExtensions();

        $file_paths = $this->file_provider->getFilesInDir($dir_name, $file_extensions);

        $files_to_scan = [];

        foreach ($file_paths as $file_path) {
            if ($allow_non_project_files || $config->isInProjectDirs($file_path)) {
                $files_to_scan[$file_path] = $file_path;
            }
        }

        $this->codebase->addFilesToAnalyze($files_to_scan);
    }

    /**
     * @param  Config $config
     *
     * @return array<int, string>
     */
    private function getAllFiles(Config $config)
    {
        $file_extensions = $config->getFileExtensions();
        $file_paths = [];

        foreach ($config->getProjectDirectories() as $dir_name) {
            $file_paths = array_merge(
                $file_paths,
                $this->file_provider->getFilesInDir($dir_name, $file_extensions)
            );
        }

        return $file_paths;
    }

    /**
     * @param  string $dir_name
     * @param  Config $config
     *
     * @return array<string>
     */
    protected function getDiffFilesInDir($dir_name, Config $config)
    {
        $file_extensions = $config->getFileExtensions();

        if (!$this->parser_cache_provider) {
            throw new \UnexpectedValueException('Parser cache provider cannot be null here');
        }

        $diff_files = [];

        $last_good_run = $this->parser_cache_provider->getLastGoodRun();

        $file_paths = $this->file_provider->getFilesInDir($dir_name, $file_extensions);

        foreach ($file_paths as $file_path) {
            if ($config->isInProjectDirs($file_path)) {
                if ($this->file_provider->getModifiedTime($file_path) > $last_good_run) {
                    $diff_files[] = $file_path;
                }
            }
        }

        return $diff_files;
    }

    /**
     * @param  Config           $config
     * @param  array<string>    $file_list
     *
     * @return void
     */
    private function checkDiffFilesWithConfig(Config $config, array $file_list = [])
    {
        $files_to_scan = [];

        foreach ($file_list as $file_path) {
            if (!$this->file_provider->fileExists($file_path)) {
                continue;
            }

            if (!$config->isInProjectDirs($file_path)) {
                $this->progress->debug('skipping ' . $file_path . "\n");

                continue;
            }

            $files_to_scan[$file_path] = $file_path;
        }

        $this->codebase->addFilesToAnalyze($files_to_scan);
    }

    /**
     * @param  string  $file_path
     *
     * @return void
     */
    public function checkFile($file_path)
    {
        $this->progress->debug('Checking ' . $file_path . "\n");

        $this->config->hide_external_errors = $this->config->isInProjectDirs($file_path);

        $this->codebase->addFilesToAnalyze([$file_path => $file_path]);

        $this->file_reference_provider->loadReferenceCache();

        $this->progress->startScanningFiles();

        $this->config->initializePlugins($this);

        $this->codebase->scanFiles($this->threads);

        $this->config->visitStubFiles($this->codebase, $this->progress);

        $this->progress->startAnalyzingFiles();

        $this->codebase->analyzer->analyzeFiles($this, $this->threads, $this->codebase->alter_code);
    }

    /**
     * @param string[] $paths_to_check
     * @return void
     */
    public function checkPaths(array $paths_to_check)
    {
        foreach ($paths_to_check as $path) {
            $this->progress->debug('Checking ' . $path . "\n");

            if (is_dir($path)) {
                $this->checkDirWithConfig($path, $this->config, true);
            } elseif (is_file($path)) {
                $this->codebase->addFilesToAnalyze([$path => $path]);
                $this->config->hide_external_errors = $this->config->isInProjectDirs($path);
            }
        }

        $this->file_reference_provider->loadReferenceCache();

        $this->progress->startScanningFiles();

        $this->config->initializePlugins($this);

        $this->codebase->scanFiles($this->threads);

        $this->config->visitStubFiles($this->codebase, $this->progress);

        $this->progress->startAnalyzingFiles();

        $this->codebase->analyzer->analyzeFiles($this, $this->threads, $this->codebase->alter_code);

        if ($this->output_format === ProjectAnalyzer::TYPE_CONSOLE && $this->codebase->collect_references) {
            fwrite(
                STDERR,
                PHP_EOL . 'To whom it may concern: Psalm cannot detect unused classes, methods and properties'
                . PHP_EOL . 'when analyzing individual files and folders. Run on the full project to enable'
                . PHP_EOL . 'complete unused code detection.' . PHP_EOL
            );
        }
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param  array<string>  $diff_files
     *
     * @return array<string, string>
     */
    public function getReferencedFilesFromDiff(array $diff_files, bool $include_referencing_files = true)
    {
        $all_inherited_files_to_check = $diff_files;

        while ($diff_files) {
            $diff_file = array_shift($diff_files);

            $dependent_files = $this->file_reference_provider->getFilesInheritingFromFile($diff_file);

            $new_dependent_files = array_diff($dependent_files, $all_inherited_files_to_check);

            $all_inherited_files_to_check = array_merge($all_inherited_files_to_check, $new_dependent_files);
            $diff_files = array_merge($diff_files, $new_dependent_files);
        }

        $all_files_to_check = $all_inherited_files_to_check;

        if ($include_referencing_files) {
            foreach ($all_inherited_files_to_check as $file_name) {
                $dependent_files = $this->file_reference_provider->getFilesReferencingFile($file_name);
                $all_files_to_check = array_merge($dependent_files, $all_files_to_check);
            }
        }

        return array_combine($all_files_to_check, $all_files_to_check);
    }

    /**
     * @param  string $file_path
     *
     * @return bool
     */
    public function fileExists($file_path)
    {
        return $this->file_provider->fileExists($file_path);
    }

    /**
     * @param int $php_major_version
     * @param int $php_minor_version
     * @param bool $dry_run
     * @param bool $safe_types
     *
     * @return void
     */
    public function alterCodeAfterCompletion(
        $dry_run = false,
        $safe_types = false
    ) {
        $this->codebase->alter_code = true;
        $this->codebase->infer_types_from_usage = true;
        $this->show_issues = false;
        $this->dry_run = $dry_run;
        $this->only_replace_php_types_with_non_docblock_types = $safe_types;
    }

    /**
     * @param array<string, string> $to_refactor
     *
     * @return void
     */
    public function refactorCodeAfterCompletion(array $to_refactor)
    {
        $this->to_refactor = $to_refactor;
        $this->codebase->alter_code = true;
        $this->show_issues = false;
    }

    /**
     * @return void
     */
    public function setPhpVersion(string $version)
    {
        if (!preg_match('/^(5\.[456]|7\.[01234])(\..*)?$/', $version)) {
            throw new \UnexpectedValueException('Expecting a version number in the format x.y');
        }

        list($php_major_version, $php_minor_version) = explode('.', $version);

        $this->codebase->php_major_version = (int) $php_major_version;
        $this->codebase->php_minor_version = (int) $php_minor_version;
    }

    /**
     * @param array<string, bool> $issues
     * @throws UnsupportedIssueToFixException
     *
     * @return void
     */
    public function setIssuesToFix(array $issues)
    {
        $supported_issues_to_fix = static::getSupportedIssuesToFix();

        $unsupportedIssues = array_diff(array_keys($issues), $supported_issues_to_fix);

        if (! empty($unsupportedIssues)) {
            throw new UnsupportedIssueToFixException(
                'Psalm doesn\'t know how to fix issue(s): ' . implode(', ', $unsupportedIssues) . PHP_EOL
                . 'Supported issues to fix are: ' . implode(',', $supported_issues_to_fix)
            );
        }

        $this->issues_to_fix = $issues;
    }

    public function setAllIssuesToFix(): void
    {
        /** @var array<string, true> $keyed_issues */
        $keyed_issues = array_fill_keys(static::getSupportedIssuesToFix(), true);

        $this->setIssuesToFix($keyed_issues);
    }

    /**
     * @return array<string, bool>
     *
     * @psalm-suppress PossiblyUnusedMethod - need to fix #422
     */
    public function getIssuesToFix()
    {
        return $this->issues_to_fix;
    }

    /**
     * @return Codebase
     */
    public function getCodebase()
    {
        return $this->codebase;
    }

    /**
     * @param  string $fq_class_name
     *
     * @return FileAnalyzer
     */
    public function getFileAnalyzerForClassLike($fq_class_name)
    {
        $fq_class_name_lc = strtolower($fq_class_name);

        $file_path = $this->codebase->scanner->getClassLikeFilePath($fq_class_name_lc);

        $file_analyzer = new FileAnalyzer(
            $this,
            $file_path,
            $this->config->shortenFileName($file_path)
        );

        return $file_analyzer;
    }

    /**
     * @param  string   $original_method_id
     * @param  Context  $this_context
     *
     * @return void
     */
    public function getMethodMutations(
        $original_method_id,
        Context $this_context,
        string $root_file_path,
        string $root_file_name
    ) {
        list($fq_class_name) = explode('::', $original_method_id);

        $appearing_method_id = $this->codebase->methods->getAppearingMethodId($original_method_id);

        if (!$appearing_method_id) {
            // this can happen for some abstract classes implementing (but not fully) interfaces
            return;
        }

        list($appearing_fq_class_name) = explode('::', $appearing_method_id);

        $appearing_class_storage = $this->classlike_storage_provider->get($appearing_fq_class_name);

        if (!$appearing_class_storage->user_defined) {
            return;
        }

        $file_analyzer = $this->getFileAnalyzerForClassLike($fq_class_name);

        $file_analyzer->setRootFilePath($root_file_path, $root_file_name);

        if (strtolower($appearing_fq_class_name) !== strtolower($fq_class_name)) {
            $file_analyzer = $this->getFileAnalyzerForClassLike($appearing_fq_class_name);
        }

        $stmts = $this->codebase->getStatementsForFile(
            $file_analyzer->getFilePath()
        );

        $file_analyzer->populateCheckers($stmts);

        if (!$this_context->self) {
            $this_context->self = $fq_class_name;
            $this_context->vars_in_scope['$this'] = Type::parseString($fq_class_name);
        }

        $file_analyzer->getMethodMutations($appearing_method_id, $this_context, true);

        $file_analyzer->class_analyzers_to_analyze = [];
        $file_analyzer->interface_analyzers_to_analyze = [];
    }

    public function getFunctionLikeAnalyzer(string $method_id, string $file_path) : ?FunctionLikeAnalyzer
    {
        $file_analyzer = new FileAnalyzer(
            $this,
            $file_path,
            $this->config->shortenFileName($file_path)
        );

        $stmts = $this->codebase->getStatementsForFile(
            $file_analyzer->getFilePath()
        );

        $file_analyzer->populateCheckers($stmts);

        $function_analyzer = $file_analyzer->getFunctionLikeAnalyzer($method_id);

        $file_analyzer->class_analyzers_to_analyze = [];
        $file_analyzer->interface_analyzers_to_analyze = [];

        return $function_analyzer;
    }

    /**
     * Adapted from https://gist.github.com/divinity76/01ef9ca99c111565a72d3a8a6e42f7fb
     * returns number of cpu cores
     * Copyleft 2018, license: WTFPL
     * @throws \RuntimeException
     * @throws \LogicException
     * @return int
     * @psalm-suppress ForbiddenCode
     */
    public static function getCpuCount(): int
    {
        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            /*
            $str = trim((string) shell_exec('wmic cpu get NumberOfCores 2>&1'));
            if (!preg_match('/(\d+)/', $str, $matches)) {
                throw new \RuntimeException('wmic failed to get number of cpu cores on windows!');
            }
            return ((int) $matches [1]);
            */
            return 1;
        }

        if (!extension_loaded('pcntl')) {
            return 1;
        }

        $has_nproc = trim((string) @shell_exec('command -v nproc'));
        if ($has_nproc) {
            $ret = @shell_exec('nproc');
            if (is_string($ret)) {
                $ret = trim($ret);
                /** @var int|false */
                $tmp = filter_var($ret, FILTER_VALIDATE_INT);
                if (is_int($tmp)) {
                    return $tmp;
                }
            }
        }

        $ret = @shell_exec('sysctl -n hw.ncpu');
        if (is_string($ret)) {
            $ret = trim($ret);
            /** @var int|false */
            $tmp = filter_var($ret, FILTER_VALIDATE_INT);
            if (is_int($tmp)) {
                return $tmp;
            }
        }

        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $count = substr_count($cpuinfo, 'processor');
            if ($count > 0) {
                return $count;
            }
        }

        throw new \LogicException('failed to detect number of CPUs!');
    }

    /**
     * @return array<string>
     */
    public static function getSupportedIssuesToFix(): array
    {
        return array_map(
            /** @param class-string $issue_class */
            function (string $issue_class): string {
                $parts = explode('\\', $issue_class);
                return end($parts);
            },
            self::SUPPORTED_ISSUES_TO_FIX
        );
    }
}
