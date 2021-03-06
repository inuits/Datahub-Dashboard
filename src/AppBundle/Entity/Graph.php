<?php

namespace AppBundle\Entity;

class Graph
{
    public $type;
    public $template;
    public $data;
    public $header;

    public $max = 0;
    public $isEmpty = false;
    public $emptyText = '';
    public $isFull = false;
    public $fullText = '';
    public $canDownload = false;
    public $legendText = '';
    public $bottomLegend = '';

    public function __construct($type, $data, $header = '')
    {
        $this->type = $type;
        $this->template = $type . '.html.twig';
        $this->data = $data;
        $this->header = $header;
    }
}
