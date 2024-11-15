<?php declare(strict_types=1);

namespace WtTools;

enum VromfsType: string
{
    case NOT_PACKED = 'not_packed';
    case ZSTD_PACKED = 'zstd_packed';
    case ZSTD_PACKED_NOCHECK = 'zstd_packed_nocheck';
    case ZLIB_PACKED = 'zlib_packed';
    case MAYBE_PACKED = 'maybe_packed';
    case TRAP = 'hoo';
}