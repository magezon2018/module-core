<?php
namespace Magezon\Core\Framework\Controller\Result;

class Json extends \Magento\Framework\Controller\Result\Json
{
    public function getJson() {
        return $this->json;
    }
}
