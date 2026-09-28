<?php

declare(strict_types=1);

namespace Fuxico;

/**
 * Pure PHP lightweight ZIP archive builder.
 *
 * Implements standard PKZIP 2.0 format with DEFLATE (gzdeflate) compression.
 * Does not require ext-zip or external binaries.
 */
class SimpleZip
{
    /** @var array<string, string> */
    private array $files = [];

    public function addFile(string $name, string $data): void
    {
        $cleanName = str_replace('\\', '/', ltrim($name, '/'));
        $this->files[$cleanName] = $data;
    }

    public function build(): string
    {
        $ctrlDir = '';
        $dataBlocks = '';
        $offset = 0;
        $time = time();

        $year = (int) date('Y', $time);
        $dosYear = max(0, $year - 1980);
        $dtime = ($dosYear << 25) | ((int) date('m', $time) << 21) | ((int) date('d', $time) << 16) |
                 ((int) date('H', $time) << 11) | ((int) date('i', $time) << 5) | ((int) ((int) date('s', $time) / 2));

        foreach ($this->files as $name => $data) {
            $uncLen = strlen($data);
            $crc = crc32($data);

            $deflated = function_exists('gzdeflate') ? @gzdeflate($data) : false;
            if ($deflated !== false && strlen($deflated) < $uncLen) {
                $compData = $deflated;
                $compMethod = 8; // DEFLATE
            } else {
                $compData = $data;
                $compMethod = 0; // STORE
            }
            $cLen = strlen($compData);

            // Local File Header
            $localHeader = "\x50\x4b\x03\x04".
                           pack('v', 20).         // Version needed to extract (2.0)
                           pack('v', 0).          // General purpose bit flag
                           pack('v', $compMethod). // Compression method
                           pack('V', $dtime).      // Mod time & date
                           pack('V', $crc).        // CRC32
                           pack('V', $cLen).       // Compressed size
                           pack('V', $uncLen).     // Uncompressed size
                           pack('v', strlen($name)). // File name length
                           pack('v', 0);           // Extra field length

            $dataBlocks .= $localHeader.$name.$compData;

            // Central Directory Record
            $ctrlRecord = "\x50\x4b\x01\x02".
                          pack('v', 20).          // Version made by (2.0)
                          pack('v', 20).          // Version needed (2.0)
                          pack('v', 0).           // Bit flag
                          pack('v', $compMethod).  // Compression method
                          pack('V', $dtime).       // Mod time & date
                          pack('V', $crc).         // CRC32
                          pack('V', $cLen).        // Compressed size
                          pack('V', $uncLen).      // Uncompressed size
                          pack('v', strlen($name)). // File name length
                          pack('v', 0).            // Extra field length
                          pack('v', 0).            // File comment length
                          pack('v', 0).            // Disk number start
                          pack('v', 0).            // Internal file attributes
                          pack('V', 32).           // External file attributes (regular archive file)
                          pack('V', $offset);      // Relative offset of local header

            $ctrlDir .= $ctrlRecord.$name;

            $offset += strlen($localHeader) + strlen($name) + $cLen;
        }

        $totalFiles = count($this->files);

        // End of Central Directory Record (EOCD)
        $eocd = "\x50\x4b\x05\x06".
                pack('v', 0).              // Number of this disk
                pack('v', 0).              // Disk with central directory
                pack('v', $totalFiles).     // Entries on this disk
                pack('v', $totalFiles).     // Total entries
                pack('V', strlen($ctrlDir)).// Size of central directory
                pack('V', $offset).         // Offset of central directory
                pack('v', 0);               // Comment length

        return $dataBlocks.$ctrlDir.$eocd;
    }
}
