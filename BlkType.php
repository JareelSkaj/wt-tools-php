<?php declare(strict_types=1);

namespace WtTools;

enum BlkType: int
{
    case FAT = 1;
    case FAT_ZSTD = 2;
    case SLIM = 3;
    case SLIM_ZSTD = 4;
    case SLIM_SZTD_DICT = 5;
}