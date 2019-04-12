<?php
/**
 * Created by PhpStorm.
 * User: dnkolosov
 * Date: 15.01.2018
 * Time: 13:35
 * @author Denis Kolosov <kdnn@mail.ru>
 */

namespace Leonied7\Yandex\Disk\Result;

use Leonied7\Yandex\Disk\Entity\Result;

/**
 * Class Move осуществляет работу с результатов ответа типа Move
 * @package Leonied7\Yandex\Disk\Result
 */
class Move extends Result
{
    /**
     * {@inheritdoc}
     */
    protected function getGoodCode()
    {
        return [
            201
        ];
    }
}