#!/usr/bin/env php
<?php declare(strict_types=1);

namespace WtTools;

require_once __DIR__ . '/VromfsUnpacker.php';

/**
 * Displays help text for the command
 */
function showHelp(): void
{
    echo <<<HELP
vromfs_unpacker: a tool built for unpacking of the Virtual ROM file system (.vromfs.bin) files.
This implementation is based on the original Python tool for extracting vromfs.bin files, the vromfs_unpacker.py, which was
a part of the wt-tools package, independently developed by a War Thunder community member nicknamed klensy,
released in January 2015, published at https://github.com/klensy/wt-tools (not compatible with newer versions of .vromfs.bin files).
The maintenance was taken over by kotiq since August 2021 and published on GitHub at https://github.com/kotiq/wt-tools
This PHP version is a rewrite, made on 15th November 2024, published at https://github.com/JareelSkaj/wt-tools-php
It's developed for PHP 8.3 and requires Zstd PHP extension to work.

Usage: vromfs_unpacker.php <filename> [options]

Arguments:
  filename                  vromfs file to unpack

Options:
  -O, --output=<path>       path where to unpack vromfs file
                            by default is FILENAME with appended _u, like some.vromfs.bin_u
  
  --metadata                if present, prints metadata of vromfs file: json with filename: md5_hash
                            if --output option used, prints to file instead
  
  --input-filelist=<file>   pass the file with list of files you want to unpack
                            files should be in json list format, like:
                            ["buildtstamp", "gamedata/units/tankmodels/fr_b1_ter.blk"]
                            or one file per line, like:
                            version
                            worldwar/maps/1v1_test_wwmap.blk
                            you can even copy-paste output from --dry-run option, like:
                            version                                                          9.00 B       File
                            worldwar/maps/berlin_wwmap.blk                                 27.22 KB      BLK
  
  -D, --dry-run             show what would be extracted without actually extracting
  
  --silent                  suppress all output except errors
  
  --no-memory-check         disable memory check
                            with this option you are more likely to get "PHP Fatal error:  Allowed memory size" error
                            default behaviour is to provide a warning if your memory limit is clearly too low for a given file

  -h, --help                display this help message

Examples:
  php vromfs_unpacker.php some.vromfs.bin
    Will unpack content to some.vromfs.bin_u folder
  
  php vromfs_unpacker.php some.vromfs.bin --output my_folder
    Will unpack some.vromfs.bin folder to my_folder
  
  php vromfs_unpacker.php some.vromfs.bin --metadata
    Will print only file metadata
  
  php vromfs_unpacker.php some.vromfs.bin --input-filelist my_filelist.txt
    Will unpack only files listed in my_filelist.txt

  php vromfs_unpacker.php some.vromfs.bin --dry-run
    Will show what files would be extracted without actually extracting them
HELP;
}

/**
 * Checks if required extensions are installed and provides installation instructions if needed
 * 
 * @throws \RuntimeException if required extensions are missing
 */
function checkRequirements(): void
{
    if (!extension_loaded('zstd')) {
        $os = PHP_OS_FAMILY;
        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        
        // Get system paths
        $extDir = str_replace('\\', '/', ini_get('extension_dir'));
        $iniPath = php_ini_loaded_file();
        $iniPath = $iniPath ? str_replace('\\', '/', $iniPath) : 'php.ini file not found';
        
        $baseMessage = <<<MSG
The Zstd extension is required but not installed.

MSG;
        
        $instructions = match($os) {
            'Windows' => <<<WINDOWS
To install Zstd extension on Windows:
1. Download the appropriate DLL for PHP {$phpVersion} from https://pecl.php.net/package/zstd 
   (avoid .tgz version, you you likely want Thread Safe (TS) dll in zip, such as php_zstd-0.14.0-{$phpVersion}-ts-vs16-x64.zip)
2. Place the php_zstd.dll file in your PHP extension directory:
   {$extDir}
3. Add 'extension=zstd' to your php.ini file:
   {$iniPath}
4. Restart your web server if applicable (php -r "clearstatcache();")
   (if you are getting error that the PHP Startup could not find the path, you might want to edit the extension_dir in php.ini)
   (e.g. set it to extension_dir = "ext")
WINDOWS,
            
            'Linux' => <<<LINUX
To install Zstd extension on Linux:
1. Install required packages:
   Ubuntu/Debian: sudo apt-get install php-dev libzstd-dev
   CentOS/RHEL: sudo yum install php-devel libzstd-devel
2. Install the extension:
   sudo pecl install zstd
3. Add 'extension=zstd.so' to your php.ini file
LINUX,
            
            'Darwin' => <<<MACOS
To install Zstd extension on macOS:
1. Install Homebrew if not already installed: https://brew.sh
2. Install required packages:
   brew install zstd
3. Install the extension:
   pecl install zstd
4. Add 'extension=zstd.so' to your php.ini file
MACOS,
            
            default => <<<DEFAULT
Please visit https://pecl.php.net/package/zstd for installation instructions for your platform.
DEFAULT
        };

        throw new \RuntimeException($baseMessage . $instructions);
    }
}

/**
 * Gets the PHP extension directory path
 */
function getExtensionDir(): string
{
    $extDir = php_ini_loaded_file() 
        ? ini_get('extension_dir') 
        : 'php extension directory not found';
    return str_replace('\\', '/', $extDir);
}

/**
 * Gets the loaded php.ini file path
 */
function getPhpIniPath(): string
{
    $iniPath = php_ini_loaded_file();
    return $iniPath 
        ? str_replace('\\', '/', $iniPath)
        : 'Could not fine the path to the php.ini - it should be in the same folder as php executable, if it doesn\'t exist, rename php.ini-development to php.ini';
}

/**
 * Main entry point for the CLI application
 */
function main(array $argv): int
{
    try {
        // Check requirements first
        checkRequirements();

        // Remove script name from arguments
        array_shift($argv);
        
        // Separate options and filename
        $filename = null;
        $options = [];
        
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            
            if (str_starts_with($arg, '-')) {
                // Handle options
                switch ($arg) {
                    case '-h':
                    case '--help':
                        $options['help'] = true;
                        break;
                    case '-O':
                    case '--output':
                        $options['output'] = $argv[++$i] ?? null;
                        break;
                    case '--metadata':
                        $options['metadata'] = true;
                        break;
                    case '--input-filelist':
                        $options['input-filelist'] = $argv[++$i] ?? null;
                        break;
                    case '-D':
                    case '--dry-run':
                        $options['dry-run'] = true;
                        break;
                    case '--silent':
                        $options['silent'] = true;
                        break;
                    case '--no-memory-check':
                        $options['no-memory-check'] = true;
                        break;
                }
            } else {
                // First non-option argument is the filename
                $filename = $arg;
            }
        }

        // Show help if requested or no filename provided
        if (isset($options['help']) || $filename === null) {
            showHelp();
            return isset($options['help']) ? 0 : 1;
        }

        $outputPath = $options['output'] ?? null;
        $inputFilelist = $options['input-filelist'] ?? null;
        $isDryRun = isset($options['dry-run']);
        $isSilent = isset($options['silent']);
        // by default memory limit is 4 times the file size, but the lang.vromfs.bin requires waaaaaay more
        $isMemoryCheck = isset($options['no-memory-check']) ? 0 : (str_contains($filename, 'lang') ? 17 : 4);

        // Create unpacker instance
        $unpacker = new VromfsUnpacker(
            filename: $filename,
            outputPath: $outputPath,
            inputFilelist: $inputFilelist,
            dryRun: $isDryRun,
            silent: $isSilent,
            defaultMemoryMultiplier: $isMemoryCheck
        );

        if (isset($options['metadata'])) {
            if ($outputPath) {
                $unpacker->filesListInfo($filename, $outputPath);
            } else {
                echo $unpacker->filesListInfo($filename), "\n";
            }
        } else {
            // Determine output path
            if ($outputPath) {
                $outputPath = $outputPath . DIRECTORY_SEPARATOR . basename($filename);
            } else {
                $outputPath = $filename . '_u';
            }

            $unpacker->unpack($filename, $outputPath, $inputFilelist);
        }

        return 0;
    } catch (\Exception $e) {
        fwrite(STDERR, "[FAIL] " . $e->getMessage() . "\n");
        return 1;
    }
}

// Run the application
exit(main($argv));