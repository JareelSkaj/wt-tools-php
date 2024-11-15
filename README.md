# wt-tools in PHP

The goal of this project is to build a PHP-native version of `wt-tools`.

This implementation is based on the original Python wt-tools package, developed independently from Gaijin by a War Thunder community member nicknamed klensy. It was released in January 2015 and published at https://github.com/klensy/wt-tools (not compatible with newer versions of .vromfs.bin files). Maintenance was taken over by kotiq in August 2021 and published on GitHub at https://github.com/kotiq/wt-tools.

The initial release includes only `vromfs_unpacker.php` - the name and all the options are kept the same as in the Python version for ease of use. Subsequent files will follow (the BLK extractor is next). Additional tooling will also be provided where I find it to be relevant. Classes are designed to be reusable outside of the CLI files, so you can easily integrate them into your own projects!

## vromfs_unpacker

A tool built for unpacking Virtual ROM file system (.vromfs.bin) files.

This PHP version is a rewrite, created on 15th November 2024 and published here. :)

It is developed for **PHP 8.3** and requires the Zstd PHP extension to work.

The implementation is designed to be as foolproof as possible and includes several minor improvements over the Python version.

Performance-wise, your RAM and storage I/O will be the biggest bottlenecks. While the PHP layer (including the extension) is faster than the Python implementation, the difference is ultimately negligible.

```
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
```
