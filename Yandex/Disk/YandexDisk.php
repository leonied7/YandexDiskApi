<?php

/**
 * Created by PhpStorm.
 * User: dnkolosov
 * Date: 20.01.2017
 * Time: 14:23
 */
namespace Yandex\Disk;

use Yandex\Common\CurlResponse;
use Yandex\Common\CurlWrapper;

class YandexDisk
{
    protected $token;

    protected $url = "https://webdav.yandex.ru";

    /**
     * последний ответ курла
     * @var CurlResponse
     */
    protected $lastResponse;

    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    function __construct($token)
    {
        if(!$token)
            throw new \InvalidArgumentException('token is required parameter');

        $this->token = $token;
    }

    private function getPath($path)
    {
        return $this->url . $this->correctPath($path);
    }

    /**
     * получение содержимого папки $path, так же по стандарту из результата удаляется текущая папка, если требуется оставить установить $thisFolder в true
     * $offset и $amount указаны в документации
     *
     * @link https://tech.yandex.ru/disk/doc/dg/reference/propfind_contains-request-docpage/
     *
     * @param $path
     * @param int $offset
     * @param null $amount
     * @param bool $thisFolder
     *
     * @return array
     * @throws \Exception
     */
    public function directoryContents($path, $offset = 0, $amount = null, $thisFolder = false)
    {
        if(!$path)
            throw new \Exception('path is required parameter');

        $response = new CurlWrapper('PROPFIND', $this->getPath($path), [
            'headers' => [
                'Depth'         => '1',
                'Authorization' => "OAuth {$this->token}"
            ],
            'query'   => [
                'offset' => $offset,
                'amount' => $amount
            ]
        ]);

        $this->lastResponse = $response->exec();

        $decodedBody = $this->getDecode($this->lastResponse->getBody());

        $contents = [];

        foreach($decodedBody as $element)
        {
            if(!$thisFolder && ($element['href'] === $this->correctPath($path)))
                continue;

            $result = $element['propstat']['prop'];

            $result['collection'] = $result['resourcetype'] == 'collection' ? 'dir' : 'file';

            $contents[] = $result;
        }

        return $contents;
    }

    /**
     * получение свободного/занятого места
     * можно получить что-то одно, если указать available/used
     *
     * @link https://tech.yandex.ru/disk/doc/dg/reference/propfind_space-request-docpage/
     *
     * @param string $info
     *
     * @return array|string
     */
    public function spaceInfo($info = '')
    {
        $prop = false;

        switch($info)
        {
            case 'available':
                $prop = 'quota-available-bytes';
                $info = "<D:{$prop}/>";
                break;
            case 'used':
                $prop = 'quota-used-bytes';
                $info = "<D:{$prop}/>";
                break;
            default:
                $info = '<D:quota-available-bytes/><D:quota-used-bytes/>';
                break;
        }

        $response = new CurlWrapper('PROPFIND', $this->getPath('/'), [
            'headers' => [
                'Depth'         => 0,
                'Authorization' => "OAuth {$this->token}"
            ],
            'body'    => "<D:propfind xmlns:D=\"DAV:\">
                                   <D:prop>{$info}</D:prop>
                              </D:propfind>"
        ]);

        $this->lastResponse = $response->exec();

        $decodedBody = $this->getDecode($this->lastResponse->getBody());

        return $decodedBody[0]['propstat']['prop'];
    }

    /**
     * получение свойств файла/каталога, вторым параметром передаётся массив свойств которые нужно вернуть
     * если свойство не найдено, оно не будет добавлено в результирующий массив
     * если оставить свойства пустыми то вернет стандартные свойства элемента как и при запросе содержимого
     *
     * @link https://tech.yandex.ru/disk/doc/dg/reference/propfind_property-request-docpage/
     *
     * @param $path
     * @param array $props
     *
     * @param string $namespace
     *
     * @return array
     * @throws \Exception
     */
    public function getProperties($path, $props = [], $namespace = 'default')
    {
        if(!$path)
            throw new \Exception('path is required parameter');

        if(!is_array($props))
            throw new \Exception('props must be array');

        $body = '';

        foreach($props as $prop)
        {
            $body .= "<{$prop} xmlns=\"{$namespace}\"/>";
        }

        unset($prop);

        $body = $body ? "<prop>{$body}</prop>" : "<allprop/>";

        $body = "<?xml version=\"1.0\" encoding=\"utf-8\" ?><propfind xmlns=\"DAV:\">{$body}</propfind>";

        $response = new CurlWrapper('PROPFIND', $this->getPath($path), [
            'headers' => [
                'Depth'          => 0,
                'Authorization'  => "OAuth {$this->token}",
                'Content-Length' => strlen($body),
                'Content-Type'   => 'application/x-www-form-urlencoded'
            ],
            'body'    => $body
        ]);

        $this->lastResponse = $response->exec();

        $decodedBody = $this->getDecode($this->lastResponse->getBody());

        $answer = (array)$decodedBody->children('DAV:')->response;

        $arProps = $answer['propstat'];

        $arProps = is_array($arProps) ? $arProps : array($arProps);

        $result = [];

        foreach($arProps as $arProp)
        {
            if(strpos($arProp->status, '200 OK') === false)
                continue;

            $arPropsResult = empty((array)$arProp->prop) ? (array)$arProp->prop->children($namespace) : (array)$arProp->prop;

            foreach($arPropsResult as $key => $prop)
            {
                $result[(string)$key] = (string)$prop;
            }

        }

        return $result;
    }

    /**
     * установка/удаление свойств для файла/папки
     *
     * https://tech.yandex.ru/disk/doc/dg/reference/proppatch-docpage/
     *
     * @param $path
     * @param array $props
     * @param string $namespace
     *
     * @return bool
     * @throws \Exception
     */
    public function setProperties($path, $props = [], $namespace = 'default')
    {
        if(!$path)
            throw new \Exception('path is required parameter');

        if(!is_array($props))
            throw new \Exception('props must be only array');

        $body = '';
        $set = '';
        $remove = '';

        foreach($props as $key => $value)
        {
            if($value)
                $set .= "<u:{$key}>{$value}</u:{$key}>";
            else
                $remove .= "<u:{$key}/>";
        }

        if($set)
            $body .= "<set><prop>{$set}</prop></set>";

        if($remove)
            $body .= "<remove><prop>{$remove}</prop></remove>";

        if(!$body)
            return false;


        $body = "<?xml version=\"1.0\" encoding=\"utf-8\" ?><propertyupdate xmlns=\"DAV:\" xmlns:u=\"{$namespace}\">{$body}</propertyupdate>";

        $response = new CurlWrapper('PROPPATCH', $this->getPath($path), [
            'headers' => [
                'Authorization'  => "OAuth {$this->token}",
                'Content-Length' => strlen($body),
                'Content-Type'   => 'application/x-www-form-urlencoded'
            ],
            'body'    => $body
        ]);

        $this->lastResponse = $response->exec();

        $decodedBody = $this->getDecode($this->lastResponse->getBody());

        if(strpos($decodedBody->children('DAV:')->response->propstat->status, '200 OK') === false)
            return false;

        return true;
    }

    /**
     * Удаление свойств у файла/папки
     * @param string $path
     * @param string|array $props
     * @param string $namespace
     *
     * @return bool
     * @throws \Exception
     */
    public function removeProperties($path, $props, $namespace = 'default')
    {
        if(!$props)
            throw new \Exception('props is required parameter');

        $props = is_array($props) ? $props : array($props);

        $arProps = [];

        foreach($props as $prop)
        {
            $arProps[$prop] = false;
        }

        return $this->setProperties($path, $arProps, $namespace);
    }

    public function startPublish($path)
    {
        if(!$path)
            throw new \Exception('path is required parameter');

        $body = "<propertyupdate xmlns=\"DAV:\">
                    <set>
                        <prop>
                            <public_url xmlns=\"urn:yandex:disk:meta\">true</public_url>
                        </prop>
                    </set>
                </propertyupdate>";

        $response = new CurlWrapper('PROPPATCH', $this->getPath($path), [
            'headers' => [
                'Authorization' => "OAuth {$this->token}",
                'Content-Length' => strlen($body)
            ],
            'body' => $body
        ]);

        $this->lastResponse = $response->exec();

        $decodedBody = $this->getDecode($this->lastResponse->getBody());

        if(!$decodedBody instanceof \SimpleXMLElement)
            throw new \Exception($this->lastResponse->getBody(), $this->lastResponse->getCode());

        print_r($this->lastResponse->getBody());
    }

    private function getDecode($body)
    {
        $dom = new \DOMDocument();

        $dom->loadXML($body);

        $result = [];

        foreach($dom->getElementsByTagName('response') as $element)
        {
            $result[] = $this->getArray($element);
        }

        return $result;
    }

    private function recurseXML($xml, &$result)
    {
        $child_count = 0;

        foreach($xml as $key => $value)
        {
            $child_count++;
            if(!$this->recurseXML($value, $result))
            {
                $result[(string)$key] = (string)$value;
            }
        }

        return $child_count;
    }

    /**
     * @param \DOMNode $node
     *
     * @return bool
     */
    private function getArray($node)
    {
        $array = false;

        if ($node->hasChildNodes())
        {
            if ($node->childNodes->length == 1)
            {
                $array = $node->firstChild->nodeValue ? $node->firstChild->nodeValue : $node->firstChild->localName;
            }
            else
            {
                foreach ($node->childNodes as $childNode)
                {
                    if ($childNode->nodeType != XML_TEXT_NODE)
                    {
                        $array[$childNode->localName] = $this->getArray($childNode);
                    }
                }
            }
        }

        return $array;
    }

    /**
     * корректирует путь
     *
     * @param $path
     *
     * @return string
     */
    private function correctPath($path)
    {
        $path = trim($path, DIRECTORY_SEPARATOR);

        return $path ? DIRECTORY_SEPARATOR . trim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : DIRECTORY_SEPARATOR;
    }
}