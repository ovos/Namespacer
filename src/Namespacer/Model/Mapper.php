<?php

namespace Namespacer\Model;

use Zend\Code\Scanner\FileScanner;

define('DIRSEP', '/');

class Mapper
{
    protected $basePath;

    protected function relativePath($path) {
        $path = str_replace('\\', DIRSEP, $path);

        if($this->basePath && substr($path, 0, strlen($this->basePath)) == $this->basePath) {
            $path = substr($path, strlen($this->basePath));
        }
        return $path;
    }

    public function getMapDataForDirectory($directory, $ignore = [], $noDirNamespacing = false)
    {
        $datas = array();

        $this->basePath = str_replace('\\', DIRSEP, realpath($directory)) . DIRSEP;

        foreach ($ignore as $key => $dir) {
            if(!$dir = str_replace('\\', DIRSEP, $dir)) {
                unset($ignore[$key]);
                continue;
            }
            $ignore[$key] = $dir;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                function ($fileInfo, $key, $iterator) use ($ignore) {
                    /** @var \SplFileInfo $fileInfo */
                    $path = $this->relativePath($fileInfo->getRealPath());

                    foreach ($ignore as $dir) {
                        if(substr($path, 0, strlen($dir)) == $dir) {
                            return false;
                        }
                    }
                    return true;
                }
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($iterator as $file) {
            /** @var $file \SplFileInfo */
            if (!in_array($file->getExtension(), ['php', 'phtml'])) {
                continue;
            }
            foreach ($this->getMapDataForFile($file->getRealPath(), $noDirNamespacing) as $key => $data) {
                $datas[$key] = $data;
            }
        }
        return $datas;
    }

    public function getMapDataForFile($file, $noDirNamespacing = false)
    {
        $file = realpath($file);

        $datas = array();
        $fs = new FileScanner($file);
        // problem in TokenArrayScanner.php line #579, needs fix (notice undefined offset 1)
        $classes = array();
        try {
            @$classes = $fs->getClassNames();
        } catch (\Exception $e) {
        }

        $classes = array_filter(array_map('trim', $classes));

        if (!count($classes)) {
            $data = array(
                'root_directory' => $this->findRootDirectory($file, ''),
                'original_class' => '',
                'original_file' => $this->relativePath($file),
                'new_namespace' => '',
                'new_class' => '',
                'new_file' => $this->relativePath($file),
            );
            $datas[$data['original_file']] = $data;
            return $datas;
        }

        $functionNames = array();
        foreach ($classes as $class) {

            $newClass = str_replace('_', '\\', $class);
            $newNamespace = substr($newClass, 0, strrpos($newClass, '\\'));
            if (strpos($newClass, '\\') !== false) {
                $newClass = substr($newClass, strrpos($newClass, '\\')+1);
            }

            $rootDir = $this->findRootDirectory($file, $class);
            if ($newNamespace) {
                if(!$noDirNamespacing) {
                } else {
                    $newFile = (!empty($rootDir) ? rtrim($rootDir, DIRSEP) . DIRSEP : '')
                        . str_replace('\\', DIRSEP, $newNamespace) . DIRSEP
                        . $newClass . '.php';
                }

                try {
                    @$functionNames = $fs->getFunctionNames();
                } catch (\Exception $e) {
                }
            } else {
                $newFile = $this->relativePath($file);
            }

            //$root = substr($file, 0, strpos($file, str_replace('\\', DIRECTORY_SEPARATOR, $newNamespace)));

            $data = array(
                'root_directory' => $rootDir,
                'original_class' => $class,
                'original_file' => $this->relativePath($file),
                'new_namespace' => $newNamespace,
                'new_class' => $newClass,
                'new_file' => $newFile,
            );

            if($functionNames) {
                $data['functions'] = $functionNames;
            }

            if($data['original_class'] != $data['new_class']) {
                // per-file transformations
                $this->transformInterfaceName($data);
                $this->transformAbstractName($data);
                $this->transformTraitName($data);
                $this->transformReservedWords($data, $noDirNamespacing);
            }

            $datas[$class] = $data;

            // per-set transformations

            // handle only one class per file otherwise it goes sideways!
            break;
        }

        if(count($classes) > 1) {
            echo "WARNING: multiple classes in $file - only the first one can be processed!\n";
        }

        return $datas;
    }

    protected function findRootDirectory($file, $class)
    {
        $rootDirParts = array_reverse(preg_split('#[\\\\/]#', $file));
        $classParts = array_reverse(preg_split('#[\\_]#', $class));

        // remove file/class
        array_shift($rootDirParts);
        array_shift($classParts);

        if (count($classParts) === 0) {
            return $this->relativePath(implode(DIRSEP, array_reverse($rootDirParts)));
        }

        while (true) {
            $curDirPart = reset($rootDirParts);
            $curClassPart = reset($classParts);
            if ($curDirPart === false || $curClassPart === false) {
                break;
            }
            if ($curDirPart === $curClassPart) {
                array_shift($rootDirParts);
                array_shift($classParts);
            } else {
                break;
            }
        }

        return $this->relativePath(implode(DIRSEP, array_reverse($rootDirParts)));
    }

    protected function transformInterfaceName(&$data)
    {
        if (strtolower($data['new_class']) !== 'interface') {
            return;
        }

        $nsParts = array_reverse(explode('\\', $data['new_namespace']));
        $data['new_class'] = $nsParts[0] . 'Interface';

//        $data['new_file'] = $data['root_directory'] . DIRECTORY_SEPARATOR
//            . str_replace('\\', DIRECTORY_SEPARATOR, $data['new_namespace']) . DIRECTORY_SEPARATOR
//            . $data['new_class'] . '.php';
    }

    protected function transformAbstractName(&$data)
    {
        if (strtolower($data['new_class']) !== 'abstract') {
            return;
        }

        $nsParts = array_reverse(explode('\\', $data['new_namespace']));
        $data['new_class'] = 'Abstract' . $nsParts[0];

//        $data['new_file'] = $data['root_directory'] . DIRECTORY_SEPARATOR
//            . str_replace('\\', DIRECTORY_SEPARATOR, $data['new_namespace']) . DIRECTORY_SEPARATOR
//            . $data['new_class'] . '.php';
    }

    protected function transformTraitName(&$data)
    {
        if (strtolower($data['new_class']) !== 'trait') {
            return;
        }

        $nsParts = array_reverse(explode('\\', $data['new_namespace']));
        $data['new_class'] = $nsParts[0] . 'Trait';

//        $data['new_file'] = $data['root_directory'] . DIRECTORY_SEPARATOR
//            . str_replace('\\', DIRECTORY_SEPARATOR, $data['new_namespace']) . DIRECTORY_SEPARATOR
//            . $data['new_class'] . '.php';
    }

    protected function transformReservedWords(&$data, $noDirNamespacing = false)
    {
        static $reservedWords = array(
            'and','array','as','break','case','catch','class','clone',
            'const','continue','declare','default','do','else','elseif',
            'enddeclare','endfor','endforeach','endif','endswitch','endwhile',
            'extends','final','for','foreach','function','global',
            'goto','if','implements','instanceof','namespace',
            'new','or','private','protected','public','static','switch',
            'throw','try','use','var','while','xor',
            'trait','interface','abstract',
            'int','string','float','bool',
        );

        $nsParts = explode('\\', $data['new_namespace']);
        foreach ($nsParts as $index => $nsPart) {
            $nsPartLower = strtolower($nsPart);
            if (in_array($nsPartLower, $reservedWords)) {
                if (in_array($nsPartLower, ['array', 'trait', 'interface'])) {
                    $nsParts[$index] = $nsPart . 's';
                } else {
                    $nsParts[$index] .= 'Namespace';
                }
            }
        }

        $data['new_namespace'] = implode('\\', $nsParts);

        if (in_array(strtolower($data['new_class']), $reservedWords)) {
            $data['new_class'] .= end($nsParts);
        }

        if($noDirNamespacing) {
            $rootDir = $this->findRootDirectory($data['original_file'], '');
            $data['new_file'] = (!empty($rootDir) ? rtrim($rootDir, DIRSEP) . DIRSEP : '')
                . str_replace('\\', DIRSEP, $data['new_class']) . '.php';
        } else {
            $data['new_file'] = rtrim($data['root_directory'], DIRSEP) . DIRSEP
                . str_replace('\\', DIRSEP, $data['new_namespace']) . DIRSEP
                . str_replace('\\', DIRSEP, $data['new_class']) . '.php';
        }
    }

}
