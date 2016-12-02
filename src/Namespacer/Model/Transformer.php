<?php

namespace Namespacer\Model;

use Zend\Code\Scanner\FileScanner;

class Transformer
{
    /** @var \Namespacer\Model\Map */
    protected $map;

    // list of reserved aliases for namespaced classes
    protected $reservedAliases = [
        'Exception',
    ];

    protected $eols = [];

    public function __construct(Map $map)
    {
        $this->map = $map;
    }

    public function moveFiles()
    {
        $fileRenamings = $this->map->getFileRenamings();
        //$this->validateFileRenamings($fileRenamings);
        foreach ($fileRenamings as $old => $new) {
            if ($old == $new) {
                continue;
            }
            //echo 'moving ' . $old . ' => ' . $new . PHP_EOL;
            if(!file_exists($old)) {
                continue;
            }
            $newDir = dirname($new);
            if (!file_exists($newDir)) {
                mkdir($newDir, 0777, true);
            }
            rename($old, $new . '.transform');
        }
        foreach ($fileRenamings as $new) {
            if (file_exists($new . '.transform')) {
                rename($new . '.transform', $new);
            }
        }
    }

    public function modifyNamespaceAndClassNames($noFileDocBlocks = false, $noNamespaceNoUse = false)
    {
        //$files = $this->map->getNewFiles();
        $fileNames = $this->map->getNameModifications();
        $classTransformations = $this->map->getClassTransformations();
        $functionTransformations = $this->map->getFunctionTransformations();
        foreach ($fileNames as $file => $names) {
            if (!file_exists($file)) {
                continue;
                //throw new \RuntimeException('The file ' . $file . ' could not be found in the filesystem, check your map file is correct.');
            }
            $hadNamespace = $this->modifyFileWithNewNamespaceAndClass($file, $names, $noFileDocBlocks);

            $this->modifyFileWithNewUseStatements($file, $classTransformations, $functionTransformations, $noNamespaceNoUse, $hadNamespace, $names['namespace']);
        }
    }

    public function modifyOriginalContentForExtension()
    {
        $extensionMap = $this->map->getExtensionMap();
        foreach ($extensionMap as $file => $extends) {
            if (!file_exists($file)) {
                throw new \RuntimeException('The file ' . $file . ' could not be found in the filesystem, check your map file is correct.');
            }
            $this->modifyOriginalFileforExtension($file, $extends);
        }
    }

    /*protected function modifyContentForUseStatements($noNamespaceNoUse = false)
    {
        $files = $this->map->getNewFiles();
        $classTransformations = $this->map->getClassTransformations();
        $functionTransformations = $this->map->getFunctionTransformations();
        foreach ($files as $file) {
            if (!file_exists($file)) {
                throw new \RuntimeException('The file ' . $file . ' could not be found in the filesystem, check your map file is correct.');
            }
            $this->modifyFileWithNewUseStatements($file, $classTransformations, $functionTransformations, $noNamespaceNoUse);
        }
    }*/

    protected function validateFileRenamings(array $fileRenamings)
    {
        $processed = array();
        foreach ($fileRenamings as $old => $new) {
            if (array_search($new, $processed)) {
                throw new \Exception('The new file ' . $new . ' is in the type map more than once, ensure there is no collision in the map file');
            }
            $processed[] = $new;
        }
        return;
    }

    protected function modifyFileWithNewNamespaceAndClass($file, $names, $noFileDocBlocks = false)
    {
        $contents = file_get_contents($file);
        $tokens = token_get_all($contents);

        if ($names['namespace'] == '') {
            return;
        }

        $eol = $this->detectEOL($contents, $file);

        $namespaceExists = false;
        foreach($tokens as $index => $token) {
            if(is_array($token) && $token[0] == T_NAMESPACE) {
                $namespaceExists = true;

                $i = $index + 2;
                while (isset($tokens[$i]) && !(is_string($tokens[$i]) && $tokens[$i] == ';')) {
                    $i++;
                }

                array_splice($tokens, $index, $i - $index, "namespace {$names['namespace']}");
            }
        }

        if(!$namespaceExists) {
            switch (true) {
                case ($tokens[0][0] === T_OPEN_TAG && $tokens[1][0] === T_DOC_COMMENT && !$noFileDocBlocks):
                    $hasWhitespace = is_array($tokens[2]) && $tokens[2][0] == T_WHITESPACE;
                    array_splice($tokens, 2, 0, $eol . $eol . "namespace {$names['namespace']};" . $eol . (!$hasWhitespace ? $eol : ""));
                    break;
                default:
                    $hasWhitespace = is_array($tokens[1]) && $tokens[1][0] == T_WHITESPACE;
                    array_splice($tokens, 1, 0, "namespace {$names['namespace']};" . $eol . (!$hasWhitespace ? $eol : ""));
                    break;
            }
        }

        $contents = '';
        $classReplaced = false;
        $token = reset($tokens);
        do {
            $key = key($tokens);
            if (!$classReplaced && $this->isClass($token[0])
                // skip "ClassName::class"
                && (!isset($tokens[$key-1]) || !is_array($tokens[$key-1]) || $tokens[$key-1][0] != T_DOUBLE_COLON)
            ) {
                $contents .= $token[1] . ' ' . $names['class'];
                next($tokens);
                next($tokens);
                $classReplaced = true;
            } else {
                $contents .= (is_array($token)) ? $token[1] : $token;
            }
        } while ($token = next($tokens));

        file_put_contents($file, $contents);

        return $namespaceExists;
    }

    protected function modifyOriginalFileforExtension($file, $names)
    {
        $tokens = token_get_all(file_get_contents($file));

        $contents = '';
        $token = reset($tokens);
        do {
            if (T_TRAIT === $token[0]) {
                $contents .= $token[1] . ' ' . $names['class'];
                $contents .= "\n{\n";
                $contents .= "    use \\" . $names['extends'] . ";";
                $contents .= "\n}\n";
                break 2;
            }
            if ($this->isClass($token[0])) {
                $contents .= $token[1] . ' ' . $names['class'];
                $contents .= " extends \\" . $names['extends'];
                $contents .= "\n{\n}\n";
                break 2;
            } else {
                $contents .= (is_array($token)) ? $token[1] : $token;
            }
        } while ($token = next($tokens));

        file_put_contents($file, $contents);
    }

    protected function modifyFileWithNewUseStatements($file, $classTransformations, $functionTransformations, $noNamespaceNoUse = false, $hadNamespace = false, $currentNamespace = '')
    {
        $contents = file_get_contents($file);
        $tokens = token_get_all($contents);

        $eol = $this->detectEOL($contents, $file);

        $ti = array();
        $token = reset($tokens);
        $normalTokens = array();
        $interestingTokens = array();
        $docTokens = array();
        $functionTokens = array();
        $definedFunctions = array();

        $hasNamespace = false;
        $usePlacement = 1;
        $currentClass = '';

        $existingUses = array();
        $existingShortNames = array();
        $existingUsesFunc = array();
        $existingShortNamesFunc = array();

        $unnecessaryNSTokens = array();


        $isUsed = function($alias) use ($currentNamespace, $currentClass, $existingUses, $classTransformations, $hadNamespace) {
            if($alias == $currentClass
                || isset($existingUses[$alias])) {
                return true;
            }
            if($currentNamespace && $hadNamespace) {
                $check = $currentNamespace . '\\' . $alias;
                foreach($classTransformations as $newClass) {
                    // check if alias is a valid class in current namespace
                    if(preg_match('/^'. preg_quote($check, '/') .'($|\\\\)/', $newClass)) {
                        return true;
                    }
                }
            }
            return false;
        };


        $inClass = false;
        do {
            // read existing uses
            $t = is_array($token) ? $token[0] : $token;
            if (($this->isClass($t)
                && (
                    ($previousKey = (key($tokens) - 1)) >= 0 // ignore ::class
                    && (!is_array($tokens[$previousKey]) || $tokens[$previousKey][0] != T_DOUBLE_COLON)
                ))
                || $t == T_FUNCTION
            ) {
                $inClass = true;
            }

            if($t == T_USE && !$inClass) {
                $index = key($tokens);
                $i = $index + 2;

                $useNs = '';
                $useNsResolved = false;
                $useAlias = '';
                $isFunction = false;
                while (isset($tokens[$i]) && !(is_string($tokens[$i]) && $tokens[$i] == ';')) {
                    if (is_array($tokens[$i])) {
                        if($tokens[$i][0] == T_FUNCTION) {
                            $isFunction = true;
                            $i++;
                        } else if ($tokens[$i][0] == T_STRING || $tokens[$i][0] == T_NS_SEPARATOR) {
                            if(!$useNsResolved) {
                                $useNs .= $tokens[$i][1];
                            } else {
                                $useAlias .= $tokens[$i][1];
                            }
                        } else if ($tokens[$i][0] == T_WHITESPACE) {
                            $useNsResolved = true;
                        }
                    }
                    $i++;
                }

                if(isset($tokens[$i + 1]) && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] == T_WHITESPACE) {
                    $i++;
                }

                if(!$useAlias) {
                    $className = explode('\\', $useNs);
                    $useAlias = end($className);
                }

                if($isFunction) {
                    $existingUsesFunc[$useAlias] = $useNs; // remember current alias in case it's gonna be changed
                    $existingShortNamesFunc[$useNs] = $useAlias;
                } else {
                    $existingUses[$useAlias] = $useNs; // remember current alias in case it's gonna be changed
                    $existingShortNames[$useNs] = $useAlias;
                }

                array_splice($tokens, $index, $i - $index + 1); // remove existing use statement from tokens
                $normalTokens = [];
                reset($tokens);
            }

            $normalTokens[] = ((is_array($token)) ? $token[0] : $token);
        } while ($token = next($tokens));


        foreach ($normalTokens as $i => $t1) {
            $t2 = isset($normalTokens[$i+1]) ? $normalTokens[$i+1] : null;
            $t3 = isset($normalTokens[$i+2]) ? $normalTokens[$i+2] : null;
            $t4 = isset($normalTokens[$i+3]) ? $normalTokens[$i+3] : null;
            $t5 = isset($normalTokens[$i+4]) ? $normalTokens[$i+4] : null;
            $t0 = isset($normalTokens[$i-1]) ? $normalTokens[$i-1] : null;
            $tn1 = isset($normalTokens[$i-2]) ? $normalTokens[$i-2] : null;

            // find if it has a namespace
            if ($i < 3 && $t1 == T_OPEN_TAG) {
                $usePlacement = $i + 1;
                if ($t2 == T_DOC_COMMENT) {
                    $usePlacement = $i + 2;
                }
            } elseif ($i == 0) {
                // put it at the beginning of the file, along with new php open tag
                $usePlacement = 0;
            }

            if ($t1 == T_NAMESPACE) {
                $hasNamespace = true;
                $usePlacement = $i + 1;
            }

            // find current class name (used for collions in uses)
            if ($this->isClass($t1) && $t2 == T_WHITESPACE && $t3 == T_STRING
                && $t0 != T_DOUBLE_COLON
            ) {
                $currentClass = $tokens[$i+2][1];
                continue;
            }

            // constant usage (or static variable) (catch only non-namespaced class)
            if ($t1 == T_STRING && $t2 == T_DOUBLE_COLON && ($t3 == T_STRING || $t3 == T_CLASS || $t3 == T_VARIABLE)
                && ($t0 != T_NS_SEPARATOR || $tn1 != T_STRING)
            ) {
                if (!in_array($tokens[$i][1], array('self', 'parent', 'static'))) {
                    if($t0 != T_NS_SEPARATOR && $isUsed($tokens[$i][1])) { // this is a class relative to current namespace
                        continue;
                    }

                    $interestingTokens[] = $i;
                    if($t0 == T_NS_SEPARATOR) {
                        $unnecessaryNSTokens[] = $i - 1;
                    }
                    continue;
                }
            }

            // instanceof
            if ($t1 == T_INSTANCEOF && $t2 == T_WHITESPACE && $t3 == T_STRING && !$isUsed($tokens[$i+2][1])) { // this is NOT a class relative to current namespace
                if (!in_array($tokens[$i+2][1], array('self', 'parent', 'static'))) {
                    $interestingTokens[] = $i+2;
                }
                continue;
            }
            if ($t1 == T_INSTANCEOF && $t2 == T_WHITESPACE && $t3 == T_NS_SEPARATOR && $t4 == T_STRING) {
                $interestingTokens[] = $i+3;
                $unnecessaryNSTokens[] = $i+2;
                continue;
            }

            // new
            if ($t1 == T_NEW && $t2 == T_WHITESPACE && $t3 == T_STRING && !$isUsed($tokens[$i+2][1])) { // this is NOT a class relative to current namespace
                if (!in_array($tokens[$i+2][1], array('self', 'parent', 'static'))) {
                    $interestingTokens[] = $i+2;
                }
                continue;
            }
            if ($t1 == T_NEW && $t2 == T_WHITESPACE && $t3 == T_NS_SEPARATOR && $t4 == T_STRING) {
                $interestingTokens[] = $i+3;
                $unnecessaryNSTokens[] = $i+2;
                continue;
            }

            // extends & implements
            if (($t1 == T_IMPLEMENTS || $t1 == T_EXTENDS) && $t2 == T_WHITESPACE
                && ($t3 == T_STRING || $t3 == T_NS_SEPARATOR)
            ) {
                // a class can implement multiple interfaces or an interface may have multiple extends
                $u = $i + 1;
                do {
                    if($normalTokens[$u] == T_NS_SEPARATOR && $normalTokens[$u - 1] == T_WHITESPACE) {
                        $unnecessaryNSTokens[] = $u;
                    }

                    if ($normalTokens[$u] == T_STRING
                        && ($normalTokens[$u - 1] != T_NS_SEPARATOR
                            || $normalTokens[$u - 2] != T_STRING)
                    ) {
                        if(!($normalTokens[$u - 1] != T_NS_SEPARATOR && $isUsed($tokens[$u][1]))) { // this is NOT a class relative to current namespace
                            $interestingTokens[] = $u;
                        }
                    }
                    $u++;
                } while (isset($normalTokens[$u]) && !in_array($normalTokens[$u], [T_IMPLEMENTS, T_EXTENDS])
                    && false === strpos($normalTokens[$u], '{'));
                continue;
            }

            // type-hints (catch only non-namespaced class)
            if ($t1 == T_STRING && $t2 == T_WHITESPACE && $t3 == T_VARIABLE
                && ($t0 != T_NS_SEPARATOR || $tn1 != T_STRING)
            ) {
                if($t0 != T_NS_SEPARATOR && $isUsed($tokens[$i][1])) { // this is a class relative to current namespace
                    continue;
                }
                $interestingTokens[] = $i;
                if($t0 == T_NS_SEPARATOR) {
                    $unnecessaryNSTokens[] = $i - 1;
                }
                continue;
            }

            // docblocks
            if ($t1 == T_DOC_COMMENT || $t1 == T_COMMENT) { // capture also comments to convert old /* @var ... */ type hinting
                $docTokens[] = $i;
                continue;
            }

            // use traits inside class (after current class definition has been found)
            if($t1 == T_USE && $t2 == T_WHITESPACE && $t3 == T_STRING && !$isUsed($tokens[$i+2][1])) { // this is NOT a class relative to current namespace
                $interestingTokens[] = $i+2;
                continue;
            }
            if ($t1 == T_NEW && $t2 == T_WHITESPACE && $t3 == T_NS_SEPARATOR && $t4 == T_STRING) {
                $interestingTokens[] = $i+3;
                $unnecessaryNSTokens[] = $i+2;
                continue;
            }


            // functions
            if($t1 == T_STRING && ($t2 == '(' || ($t2 == T_WHITESPACE && $t3 == '('))
                && $t0 != T_NS_SEPARATOR
                && isset($functionTransformations[$tokens[$i][1]])
            ) {
                $functionTokens[] = $i;
                continue;
            }

            // defined functions
            if($t1 == T_FUNCTION && $t2 == T_WHITESPACE && $t3 == T_STRING) {
                $definedFunctions[] = $tokens[$i+2][1];
                continue;
            }
        }

        $uniqueUses = $existingUses;
        $uniqueFunctions = $existingUsesFunc;

        $shortNames = array();
        $shortNamesFunc = array();

        foreach ($interestingTokens as $index) {
            $name = $tokens[$index][1];
            if (!isset($uniqueUses[$name]) && !isset($existingShortNames[$name])) {
                $uniqueUses[$name] = (isset($classTransformations[$name])) ? $classTransformations[$name] : $name;
            }
        }

        foreach ($functionTokens as $key => $index) {
            $name = $tokens[$index][1];
            if(isset($existingShortNamesFunc[$name])) {
                continue;
            }
            if(!in_array($name, $definedFunctions)) {
                $uniqueFunctions[$name] = isset($functionTransformations[$name]) ? $functionTransformations[$name] : $name;
            } else {
                unset($functionTokens[$key]);
            }
        }

        $useContent = '';
        if(($uniqueUses || $uniqueFunctions) && ($hasNamespace || !$noNamespaceNoUse)) {

            // sort uses alphabetically
            natsort($uniqueUses);
            natsort($uniqueFunctions);

            $buildUse = function($uniqueUses, $currentClass, &$shortNames, $existingShortNames, $theyAreFunctions = false) use ($eol) {
                $useContent = '';
                $prefix = $theyAreFunctions ? 'function ' : '';

                // cleanup unique uses
                foreach ($uniqueUses as $newName) {
                    if(isset($existingShortNames[$newName])) {
                        $shortNames[$newName] = $existingShortNames[$newName];
                        continue;
                    }
                    $shortName = (($shortNameStart = strrpos($newName, '\\')) !== false) ? substr($newName, $shortNameStart+1) : $newName;
                    $shortNames[$newName] = $shortName;
                }

                $shortNamesCount = array_count_values(array_map('strtolower', $shortNames));
                $dupShortNames = array_filter($shortNames, function ($item) use ($shortNamesCount, $currentClass) {
                    return (($shortNamesCount[strtolower($item)] >= 2) || strtolower($item) == strtolower($currentClass));
                });


                // array of locked namespaces - which aliases could not be resolved any further
                $locked = array();
                $backup = $shortNames;
                do {
                    $tryAgain = false;
                    $shortNames = array_merge($backup, $locked);
                    foreach ($shortNames as $fqcn => $sn) {
                        if(isset($locked[$fqcn])) {
                            continue;
                        }

                        if ((
                                isset($dupShortNames[$fqcn])
                                || in_array(strtolower($sn), array_map('strtolower', $this->reservedAliases))
                                || in_array(strtolower($sn), array_map('strtolower', $locked))
                            )
                            && substr_count($fqcn, '\\') >= 1
                        ) {
                            $parts = array_reverse(explode('\\', $fqcn));
                            $i = 2;
                            // find unique alias for use, starting with glueing last two parts
                            do {
                                $a = array_reverse(array_slice($parts, 0, $i++));
                                $alias = implode('', $a);
                                if(in_array(strtolower($alias), array_map('strtolower', $shortNames))
                                    || in_array(strtolower($alias), array_map('strtolower', $this->reservedAliases))
                                ) {
                                    $alias = '';
                                }
                            } while(!$alias && count($a) < count($parts));

                            if(!$alias) {
                                // okay, use full name without separators as alias, and lock it out,
                                // so that other use aliases should resolve to something different than this
                                $alias = implode('', $a);
                                $locked[$fqcn] = $alias;
                                $tryAgain = true;
                                break;
                            }
                            $shortNames[$fqcn] = $alias;

                        } else {
                            $i = 0;
                            $alias = $sn;
                            $base = ($theyAreFunctions ? 'base' : 'Base') . ucfirst($sn);
                            while(
                                (
                                    !in_array(strtolower($alias), array_map('strtolower', $this->reservedAliases))
                                    || in_array(strtolower($currentClass), array_map('strtolower', $this->reservedAliases))
                                ) && (
                                    in_array(strtolower($alias), array_map('strtolower', array_keys($dupShortNames)))
                                    || in_array(strtolower($alias), array_map('strtolower', $locked))
                                )
                            ) {
                                $alias = $base . ($i > 0 ? $i : '');
                                $i++;
                            }

                            $shortNames[$fqcn] = $alias;
                        }
                    }
                } while ($tryAgain);

                foreach ($shortNames as $fqcn => $sn) {
                    $lastPart = (false !== ($shortNameStart = strrpos($fqcn, '\\'))) ? substr($fqcn, $shortNameStart+1) : $fqcn;
                    if($lastPart != $sn) {
                        $useContent .= "use $prefix$fqcn as " . $sn . ";" . $eol;
                    } else {
                        $useContent .= "use $prefix$fqcn;" . $eol;
                    }
                }

                return $useContent;
            };

            $useContent .= $buildUse($uniqueUses, $currentClass, $shortNames, $existingShortNames);
            $useContent .= $buildUse($uniqueFunctions, '', $shortNamesFunc, $existingShortNamesFunc, true);

        } else {
            $usePlacement = -1;
        }

        $docBlocks = [];
        foreach($docTokens as $index) {
            $doc = $tokens[$index][1];
            $docBlocks[$index] = $this->modifyDocBlock($doc, $uniqueUses, $shortNames, $classTransformations, $hasNamespace, $hadNamespace, $isUsed);
        }


        $contents = '';
        $token = reset($tokens);

        do {
            if (key($tokens) == $usePlacement) {
                if($usePlacement == 0) {
                    // special case - but use statements at the beginning of the file with new php tag
                    $contents .= "<?php" . $eol . $eol;
                    $contents .= $useContent . $eol;
                    $contents .= "?>" . $eol;
                    $contents .= (is_array($token)) ? $token[1] : $token;
                    continue;
                }

                if ($hasNamespace) {
                    do {
                        $contents .= (is_array($token)) ? $token[1] : $token;
                    } while (($token = next($tokens)) !== false && !(is_string($token) && $token == ';'));
                    $contents .= ";" . $eol;
                } else {
                    $contents .= (is_array($token)) ? $token[1] : $token;
                    $contents .= $eol; // additional blank line after open tag
                }

                $contents .= $eol . $useContent . $eol;

                // check next token if it's blank line
                $nextKey = key($tokens) + 1;
                $nextToken = isset($tokens[$nextKey]) ? $tokens[$nextKey] : null;
                $nextToken = (is_array($nextToken)) ? $nextToken[0] : $nextToken;
                if($nextToken == T_WHITESPACE) {
                    next($tokens); // skip whitespace token
                }
            } elseif (array_search(key($tokens), $unnecessaryNSTokens) !== false
                && array_search(key($tokens) + 1, $interestingTokens) !== false) {

                // skip unneccessary namespace token
                continue;

            } elseif (array_search(key($tokens), $interestingTokens) !== false) {

                $contents .= isset($shortNames[$uniqueUses[$token[1]]])
                    ? $shortNames[$uniqueUses[$token[1]]]
                    : $uniqueUses[$token[1]];

            } elseif (array_search(key($tokens), $functionTokens) !== false) {

                $contents .= isset($shortNamesFunc[$uniqueFunctions[$token[1]]])
                    ? $shortNamesFunc[$uniqueFunctions[$token[1]]]
                    : $uniqueFunctions[$token[1]];

            } elseif (array_search(key($tokens), $docTokens) !== false) {
                $contents .= $docBlocks[key($tokens)];
            } else {
                $contents .= (is_array($token)) ? $token[1] : $token;
            }
        } while ($token = next($tokens));

        file_put_contents($file, $contents);
    }

    protected function modifyDocBlock($doc, array $uses = [], array $shortNames = [], array $classTransformations = [], $hasNamespace = true, $hadNamespace = false, &$isUsed)
    {
        $classPattern = '[a-zA-Z_][a-zA-Z0-9_]*(?:\[\])?';
        $classPattern .= '(?:\|'.$classPattern.')*';
        $patterns = [
            '/(\/\*\*? @var (?:\$[a-zA-Z0-9\->\[\]]+ )?)(' . $classPattern . ')( (?:\$[a-zA-Z0-9\->\[\]]+ )?\*\/)/',
            '/(\s\* @(?:param|return|property|var|see|uses|throws) )(' . $classPattern . ')(\s|::|$)/m',
        ];

        $callback = function($m) use ($uses, $shortNames, $classTransformations, $hasNamespace, &$isUsed) {
            $tokens = explode('|', $m[2]);
            foreach($tokens as &$token) {
                $bracketPart = '';
                if(false !== ($bracketPos = strpos($token, '['))) {
                    $bracketPart = substr($token, $bracketPos);
                    $token = substr($token, 0, $bracketPos);
                }

                if (isset($uses[$token])) {
                    $token = isset($shortNames[$uses[$token]])
                        ? $shortNames[$uses[$token]]
                        : $uses[$token];
                } else if (
                    !in_array($token, $shortNames)
                    && false === strpos($token, '\\')
                    && !in_array($token, [
                        'string', 'int', 'integer', 'float', 'bool', 'boolean', 'array', 'resource',
                        'null', 'callable', 'mixed', 'void', 'object', 'false', 'true', 'self', 'static',
                    ])
                ) {
                    if(isset($classTransformations[$token])) {
                        $token = $classTransformations[$token];
                    }
                    if($hasNamespace && !$isUsed($token)) {
                        $token = '\\' . $token;
                    }
                }

                $token .= $bracketPart;
            }
            $m[2] = implode('|', $tokens);

            return $m[1] . $m[2] . $m[3];
        };
        $doc = preg_replace_callback($patterns, $callback, $doc);
        return $doc;
    }

    protected function isClass($token)
    {
        return in_array((int)$token, [
            T_CLASS,
            T_INTERFACE,
            T_TRAIT,
        ], true);
    }

    public function detectEOL($content, $cacheKey = null)
    {
        if(null !== $cacheKey && array_key_exists($cacheKey, $this->eols)) {
            return $this->eols[$cacheKey];
        }

        // http://stackoverflow.com/a/40227058/3729316
        $arr = array_count_values(
            explode(
                ' ',
                preg_replace(
                    '/[^\r\n]*(\r\n|\n|\r)/',
                    '\1 ',
                    $content
                )
            )
        );
        arsort($arr);
        $eol = key($arr);

        if($cacheKey !== null) {
            $this->eols[$cacheKey] = $eol;
        }

        return $eol;
    }
}
