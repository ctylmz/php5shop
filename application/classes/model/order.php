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

class Model_Order extends ORM
{
    /**
     * Создает заказ и оповещает менеджера
     * @param array $products - массив с id продуктов в заказе
     * @param array $client - массив с данными о клиенте
     * @param array $to - массив с email и\или jabber менеджера
     * @param int $way - id способа оплаты
     * @param array $counts - в массиве ключи - id продуктов, а значения - их количество в заказе
     * @return string $message2user
     */
    public static function create($products, $client, $to, $way, $counts = null)
    {
        $message2user = 'Спасибо! Менеджер уведомлен о заказе.';

        $uid = isset($client['id']) ? $client['id'] : 0;
        $phone = $client['phone'];

        $contacts = '';
        foreach ($client as $field => $field_value)
        {
            if (in_array($field, array('address', 'username', 'phone', 'confirm')))
                continue;
            if ($field == 'email')
                $contacts .= 'Email: ' . $field_value . "\r\n";
            elseif (preg_match('|^f([0-9]+)$|', $field, $found))
            {
                $fieldArray = ORM::factory('field')->find($found[1])->as_array();
                $contacts .= $fieldArray['name'] . ': ' . $field_value . "\r\n";
            }
        }

        $id = Model::factory('Tmp_Order')->new_id();
        DB::insert('orders', array(
            'id', 'user', 'phone', 'status', 'date', 'address', 'username', 'contacts', 'pay_type'))
            ->values(array(
                $id, $uid, $phone, 1, time(), $client['address'], $client['username'], $contacts, $way))
            ->execute();

        Model::factory('Tmp_Order')->clean();

        $noWhs = array(); //массив товаров, у которых недостача на складе

        $prodArray = ORM::factory('product')
            ->where('id', 'IN', $products)->find_all()->as_array('id');

        foreach ($products as $product)
        {
            $whs = $prodArray[$product]->whs;
            $count = isset($counts[$product]) ? $counts[$product] : 1;

            if ($whs >= $count) //хватает наличия
            {
                DB::insert('ordproducts', array('id', 'product', 'count', 'whs'))
                    ->values(array(
                        $id, //id заказа
                        $product, //id товара
                        $count, //кол-во в заказе
                        ($whs >= $count) //достаточно в наличии
                    ))->execute();

                $count = $whs - $count; //сколько станет доступно на складе
            }
            else
            {
                if ($whs > 0) //Если хоть что-то есть
                    DB::insert('ordproducts', array('id', 'product', 'count', 'whs'))
                        ->values(array(
                            $id, //id заказа
                            $product, //id товара
                            $whs, //покупаем сколько есть
                            1
                        ))->execute();
                DB::insert('ordproducts', array('id', 'product', 'count', 'whs'))
                    ->values(array(
                        $id, //id заказа
                        $product, //id товара
                        $count - ($whs > 0 ? $whs : 0), //сколько не хватает
                        0
                    ))->execute();

                $count = 0; //сколько станет доступно на складе
                $noWhs[] = $product;
            }


            // Уменьшаем наличие на сладе            
            DB::update('products')
                ->set(array('whs' => $count))
                ->where('id', '=', $product)
                ->limit(1)
                ->execute();
        }

        Cache::instance()->delete('LastProd'); //в кэше были кол-ва whs, которые потеряли актуальность - удаляем этот кэш

        $message = 'В магазине на ' . $_SERVER['HTTP_HOST'] .
            ' поступил новый заказ (id' .
            $id . ') от пользователя с номером телефона ' .
            $phone . ".\r\n";
        if ($uid)
            $message .= 'Клиент зарегистрирован с id ' . $uid . ".\r\n";
        $message .= 'Заказано ' . count($products) . ' товаров.';

        if (count($noWhs))
        {
            $message .= 'В заказе есть товары, которые уже закончились в наличии ('
                . count($noWhs) . ').';
            $message2user .= '<br><br>Внимание! В заказе есть товары, закончились в наличии на момент заказа:<br><ul>';
            foreach ($noWhs as $product)
                $message2user .= '<li>' . htmlspecialchars($prodArray[$product]->name)
                    . (
                    ($prodArray[$product]->whs > 0)
                        ?
                        (' - есть только ' . $prodArray[$product]->whs . ' ед.')
                        :
                        ' - нет в наличии'
                    )
                    . '</li>';
            $message2user .= '</ul>';
        }
        $message .= "\r\n\r\n Способ оплаты: " . ORM::factory('pay_type', $way)->__get('name');

        if (isset($to['jabber']))
        {
            $conn = new XMPPHP_XMPP('jabber.ru', 5222, 'php5shop@jabber.ru', 'password', 'xmpphp');
            try
            {
                $conn->connect();
                $conn->processUntil('session_start');
                $conn->presence();
                $conn->message($to['jabber'], $message);
                $conn->disconnect();
            } catch (Exception $e)
            {
                //$e->getMessage();
            }
        }

        if (isset($to['email']))
        {
            $mail = Model::factory('PHPMailer');
            $mail->AddReplyTo($to['email'], 'no reply');
            $mail->From = $to['email'];
            $mail->FromName = 'Магазин на ' . $_SERVER['HTTP_HOST'];
            $mail->AddAddress($to['email']);
            $mail->Subject = 'Новый заказ (id' . $id . ')';
            $mail->AltBody = 'Заказ на ' . count($products) . ' товаров';
            $mail->MsgHTML('<body>' . $message . '</body>');
            $mail->WordWrap = 80;
            $mail->Send();
        }


        return $message2user;
    }

    /**
     * Возвращает полную историю заказов пользователя
     * @param int $user_id
     * @param float $pct
     * @param float $curr
     * @return array
     */
    public static function get_users_order_history($user_id, $pct, $curr)
    {
        $orders = DB::select('orders.id', 'date',
            array('state_orders.name', 'status'), array('state_orders.id', 'status_id'))
            ->from('orders')->where('user', '=', $user_id)
            ->order_by('date', 'DESC')
            ->join('state_orders', 'LEFT')->on('status', '=', 'state_orders.id')
            ->execute()->as_array();

        foreach ($orders as $i => $order)
        {
            $products = DB::select('products.name', 'products.price', 'ordproducts.*')->from('ordproducts')
                ->where('ordproducts.id', '=', $order['id'])
                ->and_where('ordproducts.whs', '>', 0)
                ->join('products')->on('products.id', '=', 'product')
                ->order_by('price', 'DESC')
                ->execute()->as_array();

            $orders[$i]['sum'] = 0;
            foreach ($products as $k => $p)
            {
                if($products[$k]['count'] == 0)
                {
                    unset($products[$k]);
                    continue;
                }
                $products[$k]['price'] = round($curr * $p['price'] * $pct, 2);
                $products[$k]['sum'] = $products[$k]['price'] * $products[$k]['count'];
                $orders[$i]['sum'] += $products[$k]['sum'];
                $products[$k]['name'] = htmlspecialchars($p['name']);
            }
            $orders[$i]['products'] = $products;
            $orders[$i]['date'] = date('d.m.y', $order['date']);
        }
        return $orders;
    }

    public static function cancel_order_by_user($user_id, $order_id)
    {
        if (0 == DB::update('orders')->set(array('status' => 6))
                ->where('status', 'NOT IN', array(4, 5, 6))
                ->and_where('user', '=', $user_id)
                ->and_where('id', '=', $order_id)
                ->limit(1)->execute()
        )
            return;

        // если операция изменения статуса произошла (запросом затронута одна строка)
        // возвращаем товары этого заказа в наличие на склад
        foreach (DB::select()->from('ordproducts')
                     ->where('id', '=', $order_id)
                     ->and_where('whs', '=', 1)
                     ->execute()->as_array() as $item)
            DB::update('products')->set(array('whs' => DB::expr('whs + ' . $item['count'])))
                ->where('id', '=', $item['product'])->limit(1)->execute();

    }
}