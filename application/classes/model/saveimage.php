<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
 * php5shop - CMS интернет-магазина
 * Copyright (C) 2010-2012 phpdreamer
 * php5shop.com
 * email: phpdreamer@rambler.ru
 * Это программа является свободным программным обеспечением. Вы можете
 * распространять и/или модифицировать её согласно условиям Стандартной
 * Общественной Лицензии GNU, опубликованной Фондом Свободного Программного
 * Обеспечения, версии 3.
 * Эта программа распространяется в надежде, что она будет полезной, но БЕЗ
 * ВСЯКИХ ГАРАНТИЙ, в том числе подразумеваемых гарантий ТОВАРНОГО СОСТОЯНИЯ ПРИ
 * ПРОДАЖЕ и ГОДНОСТИ ДЛЯ ОПРЕДЕЛЁННОГО ПРИМЕНЕНИЯ. Смотрите Стандартную
 * Общественную Лицензию GNU для получения дополнительной информации.
 * Вы должны были получить копию Стандартной Общественной Лицензии GNU вместе
 * с программой. В случае её отсутствия, посмотрите http://www.gnu.org/licenses/.
 */

/**
 * Модель сохранения изображений на сервере
 *
 *      $img = ORM::factory('saveImage');
 *      $img->saveit($url_1,$id_1); //Сначало создается очередь изображений
 *      $img->saveit($url_2,$id_2);
 *
 *      $img->init();               //а затем запускается обработка очереди в фоновом режиме
 */
class Model_SaveImage extends ORM
{

    public $ImgSize = 500; //размер изображений
    public $ImgSizeSmall = 150; //размер превью

    /**
     * Сохраняет изображения во временную очередь
     * @param string $url
     * @param int $id
     * @param int $n
     */
    public function saveit($url, $id, $n = 0)
    {
        $img = ORM::factory('saveImage');
        $img->__set('id', (int)$id);
        $img->__set('url', $url);
        $img->__set('n', (int)$n);
        $img->save();
    }

    /**
     * Сохраняет изображения на диске. Добавляет watermark и уменьшает до нужного размера
     * @param string $url
     * @param string $id
     * @return bool
     */
    public function gd($url, $id)
    { //если можно использовать file_get_contents
        if (0 === strpos($url, $_SERVER['DOCUMENT_ROOT']))
            $conents = file_get_contents($url);
        else //если нельзя, используем curl
        {
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            $conents = curl_exec($curl);
            curl_close($curl);
        }
        if (!$conents)
            return FALSE;
        $filename = APPPATH . 'cache/' . text::random('alnum', 12) . '.jpg';
        file_put_contents($filename, $conents);
        unset($conents);

        $this->gdFile($filename, $id);

        unlink($filename);
    }

    public function gdFile($filename, $id)
    {
        $smallImg = DOCROOT . 'images/products/small/' . $id . '.jpg';
        $bigImg = DOCROOT . 'images/products/' . $id . '.jpg';

        try
        {
            $image = new Kohana_Image_GD($filename);
        } catch (Kohana_Exception $ke)
        {
            return FALSE;
        }
        $image->resize($this->ImgSize, $this->ImgSize);
        $img = imagecreatefromstring($image->render());
        $mask = imagecreatefrompng(DOCROOT . 'images/watermark.png');
        imagecopy($img, $mask, 0, 0, 0, 0, $this->ImgSize, $this->ImgSize);
        imagejpeg($img, $bigImg, 95);

//        if((string)$id == (string)(int)$id)                                     //маленькие изображения сохраняем только для главной фотографии
//        {
        $image->resize($this->ImgSizeSmall, $this->ImgSizeSmall);
        $image->save($smallImg);
        @chmod($smallImg, 0666);
//        }

        @chmod($bigImg, 0666);
        return TRUE;

    }

    /**
     * Запускает рекурсивную обработку очереди изображений на сохранение.
     */
    public function init()
    {
        $curl = curl_init('http://' . $_SERVER['HTTP_HOST'] . url::base() . 'save/img');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, 1);
        curl_exec($curl);
        curl_close($curl);

    }
}