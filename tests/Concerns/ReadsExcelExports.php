<?php

namespace Tests\Concerns;

trait ReadsExcelExports
{
    /** @return array<string, string> */
    private function excelParts(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        try {
            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with($name, '.xml')) {
                    $parts[$name] = $zip->getFromIndex($i);
                    $this->assertNotFalse(simplexml_load_string($parts[$name]), "Invalid XML in $name");
                }
            }

            return $parts;
        } finally {
            $zip->close();
            unlink($path);
        }
    }
}
