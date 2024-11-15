<?php declare(strict_types=1);

namespace WtTools;

require_once("VromfsParser.php");

use WtTools\VromfsType;

class VromfsParser
{
    private const NOT_PACKED_ADDED_OFFSET = 0x10;
    private const NOT_PACKED_FILE_DATA_TABLE_OFFSET = 0x20;
    private const NOT_PACKED_FILENAME_TABLE_OFFSET = 0x40;

    private const MAGIC_VRFS = "VRFs";
    private const MAGIC_VRFX = "VRFx";

    private const PLATFORM_PC = "\x00\x00PC";
    private const PLATFORM_IOS = "\x00iOS";
    private const PLATFORM_ANDROID = "\x00and";

    private const ZSTD_PACKED_NOCHECK = 0x10;
    private const MAYBE_PACKED = 0x20;
    private const ZSTD_PACKED = 0x30;

    /**
     * Parses the VROMFS file data structure
     * 
     * This function parses the binary structure of a VROMFS file, which consists of:
     * 1. Header (16 bytes):
     *    - Magic (4 bytes): "VRFs" or "VRFx"
     *    - Platform (4 bytes): PC ("\x00\x00PC"), iOS ("\x00iOS"), or Android ("\x00and")
     *    - Original size (4 bytes)
     *    - Packed info (4 bytes): contains type (6 bits) and size (26 bits)
     * 
     * 2. Extended Header (optional, 8 bytes, only for VRFx):
     *    - Size (2 bytes)
     *    - Flags (2 bytes)
     *    - Version (4 bytes)
     * 
     * 3. Body:
     *    - Contains file data based on packing type (NOT_PACKED, ZSTD_PACKED, or ZLIB_PACKED)
     *    - May include obfuscated ZSTD data that requires deobfuscation
     * 
     * 4. MD5 (16 bytes, optional):
     *    - Present unless type is ZSTD_PACKED_NOCHECK
     * 
     * 5. Tail (variable):
     *    - Must be either empty or exactly 0x100 bytes
     * 
     * @param string $data Raw binary data of the VROMFS file
     * @return object Parsed file structure with properties:
     *                - header: Basic file information and type
     *                - ext_header: Extended header info (VRFx only)
     *                - body: Parsed file content
     *                - md5: File checksum (if present)
     *                - tail: Additional data
     * @throws \RuntimeException If file format is invalid or parsing fails
     */
    public function parse(string $data): object
    {
        try {
            $offset = 0;
            
            // Parse header
            $magic = substr($data, $offset, 4);
            $offset += 4;
            
            if (!in_array($magic, [self::MAGIC_VRFS, self::MAGIC_VRFX])) {
                throw new \RuntimeException("Incorrect file format, received header magic string: " . bin2hex($magic));
            }

            $platform = substr($data, $offset, 4);
            $offset += 4;
            
            if (!in_array($platform, [self::PLATFORM_PC, self::PLATFORM_IOS, self::PLATFORM_ANDROID])) {
                throw new \RuntimeException("Invalid platform: " . bin2hex($platform));
            }

            $originalSize = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;

            // Parse packed info (BitStruct in original)
            $packedInfo = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;
            
            $type = ($packedInfo >> 26) & 0x3F;  // top 6 bits
            $size = $packedInfo & 0x3FFFFFF;     // bottom 26 bits

            $header = (object)[
                'magic' => $magic,
                'platform' => $platform,
                'original_size' => $originalSize,
                'packed_size' => $size,
                'vromfs_type' => $type,
                'vromfs_packed_type' => $this->getVromfsPackedType($type, $size)
            ];

            // Parse extended header if VRFX
            $extHeader = null;
            if ($magic === self::MAGIC_VRFX) {
                $extHeader = (object)[
                    'size' => unpack('v', substr($data, $offset, 2))[1],
                    'flags' => unpack('v', substr($data, $offset + 2, 2))[1],
                    'version' => unpack('V', substr($data, $offset + 4, 4))[1]
                ];
                $offset += 8;
            }

            // Calculate body offset and size
            $bodyOffset = $offset;
            $bodySize = $size > 0 ? $size : $originalSize;
            
            // Parse body
            $body = $this->parseBody(substr($data, $bodyOffset, $bodySize), $header);
            
            // Move offset past body
            $offset += $bodySize;

            // Parse MD5 if present (16 bytes)
            if ($type !== self::ZSTD_PACKED_NOCHECK) {
                $md5 = substr($data, $offset, 16);
                $offset += 16;
            }

            // Get tail
            $tail = substr($data, $offset);
            if (!in_array(strlen($tail), [0, 0x100])) {
                throw new \RuntimeException("Invalid tail length: " . strlen($tail));
            }

            return (object)[
                'header' => $header,
                'ext_header' => $extHeader,
                'body' => $body,
                'md5' => $md5 ?? null,
                'tail' => $tail
            ];
        } catch (\Error $e) {
            var_dump("ERROR IS PARSER");
            if (str_contains($e->getMessage(), 'memory')) {
                throw new \RuntimeException(
                    "Memory limit exceeded while parsing VROMFS file: " . $e->getMessage(),
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * Determines the packing type based on the header information
     * This is used to decide how to handle the file body
     * 
     * @param int $type Raw type from header
     * @param int $size Packed size from header
     * @return string One of the VromfsType values
     */
    private function getVromfsPackedType(int $type, int $size): string
    {
        if ($size === 0) {
            return VromfsType::NOT_PACKED->value;
        }
        
        return match($type) {
            self::ZSTD_PACKED, self::ZSTD_PACKED_NOCHECK => VromfsType::ZSTD_PACKED->value,
            self::MAYBE_PACKED => VromfsType::ZLIB_PACKED->value,
            default => VromfsType::TRAP->value
        };
    }

    /**
     * Validates the parsed data structure
     * Ensures all required fields are present and valid
     * 
     * @param object $parsed The complete parsed file structure
     * @throws \RuntimeException If validation fails
     */
    private function validateParsedStructure(object $parsed): void
    {
        // Validate header
        if (!isset($parsed->header->magic, $parsed->header->platform, $parsed->header->original_size)) {
            throw new \RuntimeException("Invalid header structure");
        }

        // Validate body
        if (!isset($parsed->body->data->files_count, $parsed->body->data->filename_table)) {
            throw new \RuntimeException("Invalid body structure");
        }

        // Validate file counts match
        $namesCount = count($parsed->body->data->filename_table->filenames);
        $dataCount = count($parsed->body->data->file_data_table->file_data_list);
        if ($namesCount !== $dataCount || $namesCount !== $parsed->body->data->files_count) {
            throw new \RuntimeException(sprintf(
                "File count mismatch. Header: %d, Names: %d, Data: %d",
                $parsed->body->data->files_count,
                $namesCount,
                $dataCount
            ));
        }
    }

    /**
     * Helper function to read a null-terminated string from binary data
     * Used for reading filenames from the file table
     * 
     * @param string $data Raw file data
     * @param int $offset Starting position
     * @return string The extracted string
     * @throws \RuntimeException If no null terminator is found
     */
    private function readNullTerminatedString(string $data, int $offset): string
    {
        $endPos = strpos($data, "\0", $offset);
        if ($endPos === false) {
            throw new \RuntimeException("No null terminator found");
        }
        
        return substr($data, $offset, $endPos - $offset);
    }

    /**
     * Parses the body section of the file based on its packing type
     */
    private function parseBody(string $data, object $header): object
    {
        return match($header->vromfs_packed_type) {
            VromfsType::NOT_PACKED->value => $this->parseNotPackedBody($data),
            VromfsType::ZSTD_PACKED->value => $this->parseZstdPackedBody($data, $header),
            VromfsType::ZLIB_PACKED->value => $this->parseZlibPackedBody($data, $header),
            default => throw new \RuntimeException("Unsupported packing type: {$header->vromfs_packed_type}")
        };
    }

    /**
     * Parses an uncompressed body section
     */
    private function parseNotPackedBody(string $data): object
    {
        $offset = 0;
        
        // Get data start offset
        $dataStartOffset = $offset;
        
        // Read filename table offset and file count
        $filenameTableOffset = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        
        $filesCount = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        
        // Skip 8 bytes
        $offset += 8;
        
        // Read file data table offset
        $fileDataTableOffset = unpack('V', substr($data, $offset, 4))[1];
        
        // Parse filename table
        $filenameTable = $this->parseFilenameTable($data, $dataStartOffset, $filenameTableOffset, $filesCount);
        
        // Parse file data table
        $fileDataTable = $this->parseFileDataTable($data, $dataStartOffset, $fileDataTableOffset, $filesCount);
        
        return (object)[
            'data' => (object)[
                'data_start_offset' => $dataStartOffset,
                'filename_table_offset' => $filenameTableOffset,
                'files_count' => $filesCount,
                'filedata_table_offset' => $fileDataTableOffset,
                'filename_table' => $filenameTable,
                'file_data_table' => $fileDataTable
            ]
        ];
    }

    /**
     * Parses ZSTD compressed data
     */
    private function parseZstdPackedBody(string $data, object $header): object
    {
        $deobfuscatedData = $this->deobfuscateZstdData($data, $header);
        $decompressedData = zstd_decompress($deobfuscatedData);
        
        return $this->parseNotPackedBody($decompressedData);
    }

    /**
     * Parses ZLIB compressed data
     */
    private function parseZlibPackedBody(string $data, object $header): object
    {
        $decompressedData = gzuncompress($data);
        if ($decompressedData === false) {
            throw new \RuntimeException("Failed to decompress ZLIB data");
        }
        
        return $this->parseNotPackedBody($decompressedData);
    }

    /**
     * Deobfuscates ZSTD compressed data
     */
    private function deobfuscateZstdData(string $data, object $header): string
    {
        $result = '';
        $offset = 0;

        // First obfuscated part (if size >= 16)
        if ($header->packed_size >= 16) {
            $firstPart = substr($data, $offset, 16);
            $result .= $this->deobfuscate16($firstPart);
            $offset += 16;
        }

        // Middle part (unobfuscated)
        $middleSize = $header->packed_size - 
            ($header->packed_size >= 16 ? 16 : 0) - 
            ($header->packed_size >= 32 ? 16 : 0);
        // Align to 4 bytes
        $middleSize = (int)($middleSize / 4) * 4;
        $result .= substr($data, $offset, $middleSize);
        $offset += $middleSize;

        // Second obfuscated part (if size >= 32)
        if ($header->packed_size >= 32) {
            $secondPart = substr($data, $offset, 16);
            $result .= $this->deobfuscate32($secondPart);
            $offset += 16;
        }

        // Alignment tail
        if ($header->packed_size % 4 !== 0) {
            $result .= substr($data, $offset, $header->packed_size % 4);
        }

        return $result;
    }

    /**
     * Deobfuscates the first 16 bytes of ZSTD compressed data
     * 
     * This function performs XOR operations on 4 32-bit integers using a predefined key.
     * It's used to deobfuscate the header of ZSTD compressed data in VROMFS files.
     * The key pattern matches the one used in the Python implementation:
     * [0xAA55AA55, 0xF00FF00F, 0xAA55AA55, 0x12481248]
     * 
     * @param string $data The 16-byte input data to deobfuscate
     * @return string The deobfuscated 16-byte data
     * @throws \RuntimeException If data unpacking fails
     */
    private function deobfuscate16(string $data): string
    {
        $values = array_values(unpack('V4', $data));
        $key = [0xAA55AA55, 0xF00FF00F, 0xAA55AA55, 0x12481248];
        
        return pack('V4', 
            $values[0] ^ $key[0],
            $values[1] ^ $key[1],
            $values[2] ^ $key[2],
            $values[3] ^ $key[3]
        );
    }

    /**
     * Deobfuscates the last 16 bytes of ZSTD compressed data
     * 
     * This function performs XOR operations on 4 32-bit integers using a predefined key.
     * It's used to deobfuscate the footer of ZSTD compressed data in VROMFS files.
     * The key pattern matches the one used in the Python implementation:
     * [0x12481248, 0xAA55AA55, 0xF00FF00F, 0xAA55AA55]
     * Note that this is a different key pattern from deobfuscate16()
     * 
     * @param string $data The 16-byte input data to deobfuscate
     * @return string The deobfuscated 16-byte data
     * @throws \RuntimeException If data unpacking fails
     */
    private function deobfuscate32(string $data): string
    {
        $values = array_values(unpack('V4', $data));
        $key = [0x12481248, 0xAA55AA55, 0xF00FF00F, 0xAA55AA55];
        
        return pack('V4', 
            $values[0] ^ $key[0],
            $values[1] ^ $key[1],
            $values[2] ^ $key[2],
            $values[3] ^ $key[3]
        );
    }

    /**
     * Parses the filename table section of the file
     * 
     * @param string $data The complete file data
     * @param int $dataStartOffset The offset where the data section begins
     * @param int $filenameTableOffset Offset to the filename table from data start
     * @param int $filesCount Number of files to parse
     * @return object Filename table structure containing filenames array
     * @throws \RuntimeException If filename parsing fails
     */
    private function parseFilenameTable(string $data, int $dataStartOffset, int $filenameTableOffset, int $filesCount): object
    {
        $offset = $dataStartOffset + $filenameTableOffset;
        
        // Read first filename offset
        $firstFilenameOffset = unpack('V', substr($data, $offset, 4))[1];
        
        // Move to first filename
        $offset = $dataStartOffset + $firstFilenameOffset;
        
        // Read all filenames
        $filenames = [];
        for ($i = 0; $i < $filesCount; $i++) {
            $filename = $this->readNullTerminatedString($data, $offset);
            
            // Special handling for '?nm' files
            if ($filename === "\xff?nm") {
                $filename = "nm";
            }
            
            $filenames[] = (object)['filename' => $filename];
            $offset += strlen($filename) + 1; // +1 for null terminator
        }
        
        return (object)[
            'first_filename_offset' => $firstFilenameOffset,
            'filenames' => $filenames
        ];
    }

    /**
     * Parses the file data table section
     * 
     * @param string $data The complete file data
     * @param int $dataStartOffset The offset where the data section begins
     * @param int $fileDataTableOffset Offset to the file data table from data start
     * @param int $filesCount Number of files to parse
     * @return object File data table structure containing file_data_list array
     * @throws \RuntimeException If data parsing fails
     */
    private function parseFileDataTable(string $data, int $dataStartOffset, int $fileDataTableOffset, int $filesCount): object
    {
        $offset = $dataStartOffset + $fileDataTableOffset;
        $fileDataList = [];
        
        for ($i = 0; $i < $filesCount; $i++) {
            // Read file data record
            $fileDataOffset = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;
            
            $fileDataSize = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;
            
            // Read unknown bytes (8 bytes)
            $unknown = substr($data, $offset, 8);
            $offset += 8;
            
            // Calculate absolute file data offset
            $absoluteFileDataOffset = $dataStartOffset + $fileDataOffset;
            
            // Read file data
            $fileData = substr($data, $absoluteFileDataOffset, $fileDataSize);
            
            $fileDataList[] = (object)[
                'file_data_offset' => $fileDataOffset,
                'file_data_size' => $fileDataSize,
                'unknown' => bin2hex($unknown),
                'data' => $fileData
            ];
        }
        
        return (object)['file_data_list' => $fileDataList];
    }
}
