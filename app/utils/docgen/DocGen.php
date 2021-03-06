<?php

namespace utils\docgen;

Class DocgenException Extends \Exception {}
Class FilesystemException Extends \Exception {}

Class DocGen Extends Analysis
{
    /**
     * File to parse.
     *
     * @type: attribute public Array()
     * @name: $tag
     */
    protected $filePaths = array();

    /**
     * Name of the doc generate.
     *
     * @type: attribute public string
     * @name: $tag
     */
    protected $docPath;
    protected $docType;
    protected $pathsException;

    public function __construct()
    {
        $config = \core\App::$container['Config']->getDocGen();

        $this->filePaths = $config['paths'];
        $this->pathsException = $config['paths']['exceptions'];
        $this->docPath = $config['infos']['file'];
        $this->docType = $config['infos']['docType'];
    }

    /**
     * Build the structure to represent the analysis of each parsed file
     * in the comming documentation file
     *
     * @type: method
     * @param: array $analysis contain the analysis of each parsed file
     * @return: string $finalContent structured analysis to push in an array
     */
    private function buildAnalysisToArray($analysis)
    {
        $finalContent = '';

        foreach ($analysis as $key => $analys) {
            if ($key === 0) {
                if (preg_match('#function#i', $analysis[count($analysis) - 1])) {
                    $finalContent = "method";
                } else {
                    $finalContent = "attribute";
                }

                if ((count($analysis) - 2) >= 0) {
                    if (preg_match('#^@?type( ?: ?)?class[ ]*$#i', $analysis[count($analysis) - 3])) {
                        $finalContent = "class";
                    }
                }
            } else if ($key === count($analysis) - 1) {
                if (preg_match('#((public|protected|private)|(static)|(var))\s\$[a-zA-Z]+#i', $analys)) {
                    if (preg_match('#array\($#', $analys) === 1) {
                        $finalContent .= " *" . substr($analys, strpos($analys, 'p')) . ")";
                    } else {
                        $finalContent .= " *" . substr($analys, strpos($analys, 'p'), strlen($analys) - 1);
                    }
                } else if (preg_match('#function [a-zA-Z0-9]+#i', $analys) === 1) {
                    $finalContent .= " *" . substr($analys, 1);
                }
            } else {
                $finalContent .= " * " . trim($analys) . "\n";
            }
        }

        return $finalContent;
    }

    /**
     * Parse all file to get their analysis and call
     * methods to build the structure of the comming documentation file
     *
     * @type: method
     * @param: string $fileContent result of file_get_content for each file to parse
     * @param: string $file file's name to parse
     * @param: int $startKey key of each starting analysis contained in an array
     * @param: int $endKey key of each ending analysis contained in an array
     * @return: void
     */
    private function createDoc($fileContent, $file, $startKey = NULL, $endKey = NULL, $count = 0)
    {
        if (!empty($this->pattern)) {
            $pattern = $this->pattern;
        } else {
            $pattern = "#\r\n|\r|\n#";
        }

        $contents = preg_split($pattern, $fileContent);
        $contents = array_map('trim', $contents);
        $mediumContent = '';
        $startKeys = array();
        $endKeys = array();
        $headers = array();

        foreach ($contents as $key => $content) {
            if (preg_match('#^/(\*)+#', $content) === 1) {
                array_push($startKeys, $key);
            }

            if (preg_match('#^(\*)+/$#', $content) === 1) {
                array_push($endKeys, $key + 1);
            }

            if (preg_match('#^(((abstract|final|trait) +(class))|interface|class) +([\w\d]+)( +(extends|implement) +([\w\d]+))?( ?\{?)$#i', $content, $headerC) === 1) {
                $headers['className'] = $headerC[5];
                $headers['longClassName'] = $headerC[0];
            }

            if (preg_match('#^namespace +(.+)[;]{1}$#i', $content, $headerN) === 1) {
                $headers['namespace'] = $headerN[1];
            }
        }

        if (!empty($headers['namespace']) && !empty($headers['className'])) {
            $headers['fullClassName'] = $headers['namespace'] . '\\' . $headers['className'];
        } else if (!empty($headers['className'])) {
            $headers['fullClassName'] = $headers['className'];
        }

        if (is_null($startKey) && is_null($endKey)) {
            foreach ($startKeys as $key => $value) {
                $this->createDoc($fileContent, $file, $value, $endKeys[$key], $count);
                $count++;
            }
        }

        for ($i = $startKey + 1; $i <= $endKey; $i++) {
            $mediumContent .= $contents[$i];
        }

        $analysis = preg_split('#\*#', $mediumContent);
        $analysis = array_map('trim', $analysis);

        $formattedAnalysis = preg_split('#\*#', $this->buildAnalysisToArray($analysis));
        $formattedAnalysis = array_map('trim', $formattedAnalysis);

        // headers
        if (!empty($headers)) {
            if ($count === 0) {
                $this->fullContent[(int)$this->fileNumber]["header"] = $headers;
            }
        }

        // analysis
        if (count($analysis) > 1) {
            if (!empty($formattedAnalysis[1]) && preg_match("#^docgen$#", $formattedAnalysis[1]) === 1) {
                $this->fullContent[(int)$this->fileNumber]["analysis"][$count] = $formattedAnalysis;
            }
        }

        // footer
        if (count($startKeys) === $count) {
            $this->fullContent[(int)$this->fileNumber]['footer'] = trim($file);
        }
    }

    /**
     * Foreach all file set to parse to create the documentation file
     *
     * @type: method public static
     * @param: string $file contain the file name of each parsed file
     * @return: string $footerContent structured footer to append
     */
    public function create()
    {
        $globKey = NULL;

        foreach ($this->filePaths as $type => $filePath) {
            if ($type === 'folders') {
                foreach ($filePath as $file) {
                    foreach (glob($file . '/*.php') as $k => $f) {
                        if (!in_array($f, $this->pathsException)) {
                            if (($nb = count(glob($file . '/*.php'))) === ($k + 1)) {
                                if (is_null($globKey)) {
                                    $globKey = $k;
                                } else {
                                    $globKey++;
                                }
                            } else {
                                if (!is_null($globKey)) {
                                    $globKey++;
                                }
                            }

                            if (file_exists($file)) {
                                if (FALSE !== ($content = file_get_contents($f))) {
                                    $this->fileNumber = (is_null($globKey) ? $k : $globKey);
                                    $this->createDoc($content, $f);
                                } else {
                                    Throw New  FilesystemException('File ' . $f . ' can\'t be read');
                                }
                            } else {
                                Throw New FilesystemException('File ' . $f . ' doesn\'t exist');
                            }
                        }
                    }
                }
            } else if ($type === "files") {
                foreach($filePath as $k => $file) {
                    if (!in_array($file, $this->pathsException)) {
                        if (file_exists($file)) {
                            if (FALSE !== ($content = file_get_contents($file))) {
                                $this->fileNumber = $k;
                                $this->createDoc($content, $file);
                            } else {
                                Throw New  FilesystemException('File ' . $file . ' can\'t be read');
                            }
                        } else {
                            Throw New FilesystemException('File ' . $file . ' doesn\'t exist');
                        }
                    }
                }
            }
        }

        $this->process();
    }
}