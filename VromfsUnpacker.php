<?php declare(strict_types=1);

namespace WtTools;

require_once __DIR__ . '/VromfsParser.php';
require_once __DIR__ . '/BlkType.php';
require_once __DIR__ . '/VromfsType.php';
require_once __DIR__ . '/ZstdDict.php';

use WtTools\VromfsParser;
use WtTools\VromfsType;
use WtTools\BlkType;
use WtTools\ZstdDict;

class VromfsUnpacker
{
    private const MAX_OUTPUT_SIZE = 5000000;
    private readonly VromfsParser $parser;
    
    /**
     * Enum for BLK file types
     * Different compression methods used for .blk files
     */
    private const BLK_TYPE_FAT = 1;          // Uncompressed with header
    private const BLK_TYPE_FAT_ZSTD = 2;     // ZSTD compressed with header
    private const BLK_TYPE_SLIM = 3;         // Uncompressed without header
    private const BLK_TYPE_SLIM_ZSTD = 4;    // ZSTD compressed without header
    private const BLK_TYPE_SLIM_ZSTD_DICT = 5; // ZSTD compressed with dictionary
    
    public function __construct(
        private readonly string $filename,
        private readonly ?string $outputPath = null,
        private readonly ?string $inputFilelist = null,
        private readonly bool $dryRun = false,        
        private readonly bool $silent = false,
        private readonly int $defaultMemoryMultiplier = 4
    ) {
        $this->parser = new VromfsParser();
    }

    /**
     * Unpacks vromfs file into directory
     * 
     * @param string $filename
     * @param string $destDir
     * @param string|null $fileListPath
     * @return array<string>
     * @throws \RuntimeException
     */
    public function unpack(string $filename, string $destDir, ?string $fileListPath = null): array
    {
        // Get file size before reading
        $fileSize = filesize($filename);
        if ($fileSize === false) {
            throw new \RuntimeException("Could not determine file size: $filename");
        }
        if ($this->defaultMemoryMultiplier > 0 ){
            // Check if we have enough memory
            $memoryLimit = $this->getMemoryLimitBytes();
            $requiredMemory = $fileSize * $this->defaultMemoryMultiplier; // Rough estimate for decompression
            
            if ($memoryLimit > 0 && $requiredMemory > $memoryLimit) {
                throw new \RuntimeException(
                    "Not enough memory to process this file.\n" .
                    "Current limit: " . $this->formatSize($memoryLimit, 'MB') . "\n" .
                    "Required (estimated): " . $this->formatSize($requiredMemory, 'MB') . "\n\n" .
                    "To fix this, you can:\n" .
                    "1. Increase PHP memory limit by adding this to your php.ini:\n" .
                    "   memory_limit = " . $this->formatSize($requiredMemory, 'MB', 'M', true) . "\n" .
                    "   Current php.ini location: " . php_ini_loaded_file() . "\n\n" .
                    "2. Or run PHP with increased memory limit:\n" .
                    "   php -d memory_limit=" . $requiredMemory . " vromfs_unpacker.php $filename \n\n" .
                    "3. Or process specific files using --input-filelist option:\n" .
                    "   php vromfs_unpacker.php $filename --input-filelist files.json\n\n" .
                    "If you get `PHP Fatal error:  Allowed memory size (...) exhausted`, increase that value further."
                );
            }
        }

        $data = file_get_contents($filename);
        if ($data === false) {
            throw new \RuntimeException("Failed to read file: $filename");
        }

        $parsed = $this->parser->parse($data);
        $useZstd = $parsed->header->vromfs_packed_type === VromfsType::ZSTD_PACKED->value;
        $writtenNames = [];
        $totalSize = 0;

        // Process each file in the archive
        $namesNs = $parsed->body->data->filename_table->filenames;
        $dataNs = $parsed->body->data->file_data_table->file_data_list;

        // Find 'nm' file for dictionary setup
        $nmId = null;
        foreach ($namesNs as $i => $ns) {
            if ($ns->filename === 'nm') {
                $nmId = $i;
                break;
            }
        }

        // Setup ZSTD dictionary if needed
        $dict = null;
        if ($nmId !== null) {
            $dict = $this->setupZstdContext($namesNs, $dataNs, $nmId);
        }

        if ($this->dryRun && !$this->silent) {
            echo "\nAnalyzing VROMFS archive: " . basename($filename) . "\n";
            echo "Would extract to: " . $destDir . "\n\n";
            echo str_repeat('-', 80) . "\n";
            echo sprintf("%-60s %10s %10s\n", "Filename", "Size", "Type");
            echo str_repeat('-', 80) . "\n";
        }

        foreach ($namesNs as $i => $name) {
            $internalFilePath = $this->normalizeName($name->filename);
            
            // Skip if not in file list (when file list is provided)
            if ($fileListPath !== null
                && !in_array(strtolower($internalFilePath), $fileListPath, true)) {
                continue;
            }

            $unpackedFilename = $destDir . DIRECTORY_SEPARATOR . $internalFilePath;

            // Get appropriate content based on file type
            $content = match(basename($unpackedFilename)) {
                'nm' => $this->getSharedNamesContent($dataNs[$i], $dict),
                default => str_ends_with($unpackedFilename, '.blk') 
                    ? $this->getBlkContent($dataNs[$i], $dict)
                    : $dataNs[$i]->data
            };

            $contentSize = strlen($content);
            $totalSize += $contentSize;

            if ($this->dryRun) {
                $fileType = match(true) {
                    str_ends_with($internalFilePath, '.blk') => 'BLK',
                    basename($internalFilePath) === 'nm' => 'Names',
                    str_ends_with($internalFilePath, '.ddsx') => 'Texture',
                    str_ends_with($internalFilePath, '.bin') => 'Binary',
                    default => 'File'
                };

                if (!$this->silent) {
                    sprintf("%-60s %10s %10s\n",
                        strlen($internalFilePath) > 59 
                            ? '...' . substr($internalFilePath, -56) 
                            : $internalFilePath,
                        $this->formatSize($contentSize),
                        $fileType
                    );
                }
            } else {
                if ($content !== '') {
                    $this->mkdirP($unpackedFilename);
                    if (file_put_contents($unpackedFilename, $content) === false) {
                        throw new \RuntimeException("Failed to write file: $unpackedFilename");
                    }
                }
            }
            $writtenNames[] = $internalFilePath;
        }

        if ($this->dryRun && !$this->silent) {
            echo str_repeat('-', 80) . "\n";
            echo sprintf("Total: %d files, %s\n", 
                count($writtenNames),
                $this->formatSize($totalSize)
            );
            echo "\nNo files were actually extracted (dry-run mode)\n";
        }

        if (!$this->silent) {
            echo sprintf("[%s] %s => %s\n", 
                $this->dryRun ? "DRY-RUN" : "OK",
                realpath($filename), 
                realpath($destDir) ?: $destDir
            );
        }

        return $writtenNames;
    }

    /**
     * Generates metadata information about files in the archive
     * 
     * @param string $filename Source vromfs file
     * @param string|null $destFile Optional output file for metadata
     * @return string|null JSON string if no destFile, null if writing to file
     * @throws \RuntimeException
     */
    public function filesListInfo(string $filename, ?string $destFile = null): ?string
    {
        $data = file_get_contents($filename);
        if ($data === false) {
            throw new \RuntimeException("Failed to read file: $filename");
        }

        $parsed = $this->parser->parse($data);
        $namesNs = $parsed->body->data->filename_table->filenames;
        $dataNs = $parsed->body->data->file_data_table->file_data_list;
        
        $outList = [];
        foreach ($namesNs as $i => $name) {
            $outList[] = [
                'filename' => strtolower($this->normalizeName($name->filename)),
                'hash' => md5($dataNs[$i]->data)
            ];
        }

        $outJson = json_encode([
            'version' => 1,
            'filelist' => $outList
        ], JSON_THROW_ON_ERROR);

        if ($destFile === null) {
            return $outJson;
        }

        if (file_put_contents($destFile, $outJson) === false) {
            throw new \RuntimeException("Failed to write metadata file: $destFile");
        }

        if (!$this->silent) {
            echo sprintf("[OK] %s => %s\n", realpath($filename), realpath($destFile));
        }
        return null;
    }

    /**
     * Creates directory structure recursively, similar to mkdir -p
     * 
     * @param string $path Full path to create
     * @throws \RuntimeException If directory creation fails
     */
    private function mkdirP(string $path): void
    {
        // Get the directory path by removing the filename
        $dirPath = dirname($path);
        
        // If directory already exists or is empty, nothing to do
        if ($dirPath === '' || is_dir($dirPath)) {
            return;
        }
        
        // Create directory with full permissions recursively
        if (!mkdir($dirPath, 0777, true) && !is_dir($dirPath)) {
            throw new \RuntimeException(sprintf('Directory "%s" could not be created', $dirPath));
        }
    }

    /**
     * Normalizes a filename by removing leading slashes
     * 
     * @param string $name Filename
     * @return string Filename
     */
    private function normalizeName(string $name): string
    {
        return ltrim($name, '/\\');
    }


    /**
     * Gets the dictionary name from the 'nm' file data
     * War Thunder uses dictionary-based compression for some files
     * The dictionary ID is stored in the 'nm' file at offset 8
     * 
     * @param object $node File data node containing dictionary information
     * @return string|null Dictionary filename or null if no dictionary used
     */
    private function getDictName(object $node): ?string 
    {
        $offset = 8;
        $size = 32;
        $dictId = substr($node->data, $offset, $size);
        
        if ($dictId === str_repeat("\x00", $size)) {
            return null;
        }
        
        return bin2hex($dictId) . '.dict';
    }

    /**
     * Gets content for BLK files with proper decompression handling
     * Different BLK types require different decompression approaches:
     * - FAT types include a header that needs to be preserved
     * - SLIM types are pure content
     * - Dictionary-based compression requires the dictionary for decompression
     * 
     * @param object $node File data node
     * @param ZstdDict|null $dict ZSTD dictionary if available
     * @return string Decompressed content
     * @throws \RuntimeException if decompression fails
     */
    private function getBlkContent(object $node, ?ZstdDict $dict = null): string 
    {
        if ($node->file_data_size === 0) {
            return '';
        }

        $pkType = ord($node->data[0]);
        
        // Only require dictionary for SLIM_ZSTD_DICT type
        if ($pkType === self::BLK_TYPE_SLIM_ZSTD_DICT && !$dict) {
            throw new \RuntimeException("ZSTD dictionary required for dictionary-based compression type");
        }

        return match($pkType) {
            self::BLK_TYPE_FAT => substr($node->data, 1),
            self::BLK_TYPE_FAT_ZSTD => $this->decompressFatZstd($node->data, $dict),
            self::BLK_TYPE_SLIM => substr($node->data, 1),
            self::BLK_TYPE_SLIM_ZSTD, 
            self::BLK_TYPE_SLIM_ZSTD_DICT => $this->decompressWithDict(substr($node->data, 1), $dict),
            default => $node->data
        };
    }

    /**
     * Decompresses FAT ZSTD content with size header
     * 
     * @param string $data Compressed data with size header
     * @param ZstdDict|null $dict Optional ZSTD dictionary
     * @return string Decompressed content
     */
    private function decompressFatZstd(string $data, ?ZstdDict $dict = null): string 
    {
        $pkSize = unpack('V', substr($data, 1, 3) . "\x00")[1];
        $pkOffset = 4;
        $decoded = $this->decompressWithDict(
            substr($data, $pkOffset, $pkOffset + $pkSize),
            $dict
        );
        return substr($decoded, 1);
    }

    /**
     * Sets up ZSTD compression context with dictionary
     * 
     * This function finds and loads the dictionary for ZSTD compression from the 'nm' file.
     * The dictionary is used for decompressing certain .blk files that use dictionary-based compression.
     * 
     * @param array $namesNs Array of filename objects
     * @param array $dataNs Array of file data objects
     * @param int $nmId Index of the 'nm' file
     * @return ZstdDict|null Dictionary object if successful, null if no dictionary needed
     * @throws \RuntimeException If dictionary file is not found or dictionary creation fails
     */
    private function setupZstdContext(array $namesNs, array $dataNs, int $nmId): ?ZstdDict
    {
        if ($namesNs[$nmId]->filename !== 'nm') {
            return null;
        }

        // Get dictionary name from nm file
        $dictName = $this->getDictName($dataNs[$nmId]);
        if ($dictName === null) {
            return null;
        }

        // Find dictionary data
        $dictId = null;
        foreach ($namesNs as $i => $ns) {
            if ($ns->filename === $dictName) {
                $dictId = $i;
                break;
            }
        }

        if ($dictId === null) {
            throw new \RuntimeException("Dictionary file not found: $dictName");
        }

        // Create our custom dictionary wrapper
        return new ZstdDict($dataNs[$dictId]->data);
    }

    /**
     * Safely decompresses ZSTD data with optional dictionary
     */
    private function decompressWithDict(string $data, ?ZstdDict $dict = null): string 
    {
        if ($dict) {
            return $dict->decompress($data);
        }
        
        $result = \zstd_uncompress($data);
        if ($result === false) {
            throw new \RuntimeException("Failed to decompress ZSTD data");
        }
        return $result;
    }

    /**
     * Gets content from shared names file
     * These files use dictionary-based compression with a special offset
     * 
     * @param object $node File data node
     * @param ZstdDict|null $dict ZSTD dictionary
     * @return string Decompressed content
     */
    private function getSharedNamesContent(object $node, ?ZstdDict $dict): string
    {
        $pkOffset = 40;
        $compressed = substr($node->data, $pkOffset);
        return $this->decompressWithDict($compressed, $dict);
    }

    /**
     * Gets PHP memory limit in bytes
     * 
     * @return int Memory limit in bytes, -1 if unlimited
     */
    private function getMemoryLimitBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        
        // Handle no limit
        if ($memoryLimit === '-1') {
            return -1;
        }
        
        // Convert shorthand notation to bytes
        $value = (int)$memoryLimit;
        $unit = strtoupper(substr($memoryLimit, -1));
        
        return match($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int)$memoryLimit
        };
    }

    /**
     * Formats size in bytes to a human-readable KB, MB, GB
     * 
     * @param int $size Size in bytes
     * @param string|null $forceUnit Force output in specific unit ('B', 'KB', 'MB', 'GB')
     * @param string|null $forceUnitLabel Override the unit label (e.g., 'M' instead of 'MB')
     * @param bool $roundInt Round to nearest integer instead of showing decimals
     * @return string Formatted size
     */
    private function formatSize(
        int $size, 
        ?string $forceUnit = null,
        ?string $forceUnitLabel = null,
        bool $roundInt = false
    ): string {
        $format = $roundInt ? '%d%s%s' : '%.2f%s%s';
        $space = $forceUnitLabel === null ? ' ' : '';
        
        if ($forceUnit !== null) {
            $value = match(strtoupper($forceUnit)) {
                'B' => $size,
                'KB' => $size / 1024,
                'MB' => $size / (1024 * 1024),
                'GB' => $size / (1024 * 1024 * 1024),
                default => throw new \InvalidArgumentException("Invalid unit: $forceUnit")
            };
            
            $label = $forceUnitLabel ?? $forceUnit;
            return $roundInt 
                ? sprintf($format, ceil($value), $space, $label)
                : sprintf($format, $value, $space, $label);
        }

        // Auto-select unit if none forced
        if ($size < 1024) {
            return sprintf($format, $size, $space, $forceUnitLabel ?? 'B');
        } elseif ($size < 1024 * 1024) {
            return sprintf($format, $size / 1024, $space, $forceUnitLabel ?? 'KB');
        } elseif ($size < 1024 * 1024 * 1024) {
            return sprintf($format, $size / (1024 * 1024), $space, $forceUnitLabel ?? 'MB');
        } else {
            return sprintf($format, $size / (1024 * 1024 * 1024), $space, $forceUnitLabel ?? 'GB');
        }
    }
}