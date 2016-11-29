<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2012-2013 Zend Technologies USA Inc. (http://www.zend.com)))
 */

namespace Namespacer\Controller;

use Namespacer\Model\Fixer;
use Namespacer\Model\Map;
use Namespacer\Model\Mapper;
use Namespacer\Model\Transformer;
use Namespacer\Model\LegacyExtension;
use Zend\Mvc\Controller\AbstractActionController;

class Controller extends AbstractActionController
{
    public function createMapAction()
    {
        $mapfile = $this->params()->fromRoute('mapfile');
        $source  = $this->params()->fromRoute('source');
        $noDirNamespacing = $this->params()->fromRoute('no-dir-namespacing'); // do not nest classes into namespaces dirs but leave them where they are
        $ignore = $this->params()->fromRoute('ignore'); // comma-separated dir names to ignore
        $merge = $this->params()->fromRoute('merge'); // comma-separated dir names to ignore

        $ignore = $ignore ? explode(',', $ignore) : array();

        $map     = array();
        $mapper  = new Mapper();
        $mapdata = $mapper->getMapDataForDirectory($source, $ignore, $noDirNamespacing);

        if($merge && file_exists($mapfile)) {
            $data = include $mapfile;
            $mapdata = array_merge($data, $mapdata);
        }

        $content = '<' . '?php return ' . var_export($mapdata, true) . ';';

        file_put_contents($mapfile, $content);
    }

    public function transformAction()
    {
        $mapfile     = $this->params()->fromRoute('mapfile');
        $step        = $this->params()->fromRoute('step');
        $source  = $this->params()->fromRoute('source');
        $noFileDocBlocks = $this->params()->fromRoute('no-file-docblocks');
        $noNamespaceNoUse = $this->params()->fromRoute('no-namespace-no-use');

        $data        = include $mapfile;

        chdir(realpath($source));

        $map         = new Map($data);
        $transformer = new Transformer($map);

        switch ($step) {
            case '2':
                $transformer->modifyNamespaceAndClassNames($noFileDocBlocks, $noNamespaceNoUse);
                break;
            case '1':
                $transformer->moveFiles();
                break;
            default:
                $transformer->moveFiles();
                $transformer->modifyNamespaceAndClassNames($noFileDocBlocks, $noNamespaceNoUse);
                break;
        }
    }

    public function legacyExtensionAction()
    {
        $mapfile     = $this->params()->fromRoute('mapfile');
        $target      = $this->params()->fromRoute('target');
        $data        = include $mapfile;
        $map         = new Map($data);
        $transformer = new LegacyExtension($map);

        $transformer->createLegacyClasses($target);
    }

    public function fixAction()
    {
        $mapfile = $this->params()->fromRoute('mapfile');
        $source  = $this->params()->fromRoute('target');
        $data    = include $mapfile;
        $map     = new Map($data);
        $fixer   = new Fixer($map);

        $fixer->fix($source);
    }
}
