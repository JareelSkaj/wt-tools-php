<?php declare(strict_types=1);

namespace WtTools;

/**
 * Custom ZSTD dictionary implementation to match Python's zstandard.ZstdCompressionDict behavior
 * Used for dictionary-based ZSTD compression/decompression in War Thunder files (e.g. in aces.vromfs.bin)
 */
class ZstdDict
{
    public function __construct(
        private readonly string $dictData
    ) {}

    /**
     * Decompress data using this dictionary
     * 
     * @param string $data Compressed data
     * @return string Decompressed data
     * @throws \RuntimeException if decompression fails
     */
    public function decompress(string $data): string
    {
        $result = \zstd_uncompress_dict($data, $this->dictData);
        if ($result === false) {
            throw new \RuntimeException("Failed to decompress data using dictionary");
        }
        return $result;
    }
} 