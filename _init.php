<?
// подписываемся на событие смены статуса заказа
// нужно добавить это в init.php

AddEventHandler("sale", "OnSaleStatusOrder", "OnSaleStatusOrderFunc");
function OnSaleStatusOrderFunc($ID, $val)
{
	CModule::IncludeModule("iblock"); //работаем с инфоблоками
	CModule::IncludeModule("sale"); //работаем с заказами

	$arZakaz = CSaleOrder::GetByID($ID);

	//данный кусок исключительно для сайта с ID s4. если сайтов несколько и нужно прозванивать только по заказам одномго/нескольких из них то указываем в условии его ID или их ID по типу  $arZakaz['LID'] == 's4' || $arZakaz['LID'] == 's5' || $arZakaz['LID'] == 's6'
	if ($arZakaz['LID'] == 's4') {
		// новый заказ
		if ($val=="N") {
			try {

				$prozvon = false;
				$strOrderList = array();

				// перебрать товары заказа - составить список товаров
				$dbBasketItems = CSaleBasket::GetList(
					  array("NAME" => "ASC"),
					  array("ORDER_ID" => $ID),
					  false,
					  false,
					  array("ID", "NAME", "QUANTITY", "PRICE", "CURRENCY", "PRODUCT_ID")
				   );

				while ($arBasketItems = $dbBasketItems->Fetch())
				{
					$epr = CIBlockElement::GetProperty(28, $arBasketItems["PRODUCT_ID"], array("sort" => "asc"), array("CODE" => "NEED_DELIVERY")); //28 - ID инфоблока с товарами для криптостора

					while ($ob = $epr->GetNext()) {
						if ($ob['VALUE'] != 9) { //9 = физ поставка
							$prozvon = true;
						}
					}

					$db_props = CIBlockElement::GetProperty(28, $arBasketItems["PRODUCT_ID"], array("sort" => "asc"), Array("CODE"=>"SPEECHNAME"));

					$SPEECHNAME = "";
					if($ar_props = $db_props->Fetch()) {
						$SPEECHNAME = $ar_props["VALUE"];
					}

				   $strOrderList[] = $SPEECHNAME;
				}
				$strOrderList = implode(". ",$strOrderList);


				 //получаем заказ по ID
				$orders = CSaleOrder::GetList(Array("ID"=>"DESC"),Array("ID"=>$ID),false,false,Array("*"));

				if ($order = $orders->Fetch()) {
					$rub = intval($order['PRICE']);
					$kop = round(($order['PRICE'] - $rub)*100);
					$strOrderList .= " на сумму " . $rub . " рублей " . $kop . " копеек";
					$PERSON_TYPE_ID = $order['PERSON_TYPE_ID'];
					$PAY_SYSTEM_ID = $order['PAY_SYSTEM_ID'];
					// file_put_contents("/home/bitrix/www/bitrix/php_interface/log.txt", PHP_EOL . print_r($order,true), FILE_APPEND);
				}

				if ($prozvon && $PERSON_TYPE_ID == 6 && $PAY_SYSTEM_ID == 21) {

					// 48 и 37 - это ID свойств заказа EMAIL в нашем случае
					$p_p = CSaleOrderPropsValue::GetList(array(),array('ORDER_PROPS_ID'=>'48','ORDER_ID'=>$ID),false,false,array()); // для Юр. Лица
					$pp =  $p_p->Fetch();
					$EMAIL = $pp['VALUE'];
					if ($EMAIL == '') {
						$p_p = CSaleOrderPropsValue::GetList(array(),array('ORDER_PROPS_ID'=>'37','ORDER_ID'=>$ID),false,false,array()); // для физ лица
						$pp =  $p_p->Fetch();
						$EMAIL = $pp['VALUE'];
					}

					// 49 - ID свойства номера телефона для Юрика, 38 - для физика, зависит от настроек свойств заказа
					$p_p = CSaleOrderPropsValue::GetList(array(),array('ORDER_PROPS_ID'=>'49','ORDER_ID'=>$ID),false,false,array()); // для Юр. Лица
					$pp =  $p_p->Fetch();
					$phone = $pp['VALUE'];

					if ($phone == '') {
						$p_p = CSaleOrderPropsValue::GetList(array(),array('ORDER_PROPS_ID'=>'38','ORDER_ID'=>$ID),false,false,array()); // для физ лица
						$pp =  $p_p->Fetch();
						$phone = $pp['VALUE'];
					}

					// если есть телефон - то приводим его к общему формату
					if ($phone) {
						/*
						$phone = str_replace(" ","",$phone);
						$phone = str_replace("-","",$phone);
						$phone = str_replace("(","",$phone);
						$phone = str_replace(")","",$phone);
						*/
						$phone = preg_replace("/[^0-9]/","",$phone);
						$phone = substr($phone,0,11);

					}

					$login = "login";
					$password = "password";

					// передать данные заказа
					$arParams = array(
						"number"	=> $ID,
						"orders"	=> $strOrderList,
						"email"		=> $EMAIL,
						"phone"		=> $phone,
						"voice"		=> "oksana",
						"token"		=> base64_encode($login.":".$password)
					);

					$content = http_build_query($arParams);

					// адрес сервера прозвона
					$url = "https://api.kloud.one/users/tasks?".$content;
					//  Initiate curl
					$ch = curl_init($url);
					// Will return the response, if false it print the response
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					// таймаут 2 секунды на соединение
					//curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
					// таймаут 2 секунды на весь запрос с соединением
					curl_setopt($ch, CURLOPT_TIMEOUT, 5);
					// Execute
					$result=curl_exec($ch);
					// Closing
					curl_close($ch);

					// логируем отправку запроса от магазина на сервер прозвона
					file_put_contents("/home/bitrix/www/bitrix/prozvonlog.html", PHP_EOL . "<br><hr>" . date("Y-m-d H:i:s") . " " . $content . "<br> ответ сервера: " . $result, FILE_APPEND);

					$result = json_decode($result,true);
					if ($result["success"] == true) {
						$resultMsg = " прозвон заказа " . $ID . " размешен успешно";
					} else {
						$resultMsg = " прозвон заказа " . $ID . " НЕ размешен. " . $result["msg"] . " <br>" . print_r($result,true);
					}

					// если нету PHPMailer - можно через mail() слать письмо
					// отправить письмо со статусом передачи заказа в обзвон
					require '/home/bitrix/www/pmailer/PHPMailerAutoload.php';

					$mail = new PHPMailer;
					$mail->CharSet = 'UTF-8';
					$mail->setFrom('sales@____.ru', 'Интернет-магазин ____.Ru');
					$mail->addReplyTo('sales@____.ru', 'Интернет-магазин ____.Ru');

					$mail->addAddress('sales@____.ru', 'Интернет-магазин ____.Ru');

					$mail->Subject = 'Отчет по размещению прозвона заказа ' . $ID . '. Интернет-магазин ____.Ru';
					$mail->msgHTML($resultMsg);
					$mail->AltBody = strip_tags($resultMsg);
					if (!$mail->send()) {
						file_put_contents("/home/bitrix/www/bitrix/prozvonlog.html", PHP_EOL . "<br>" . date("Y-m-d H:i:s") . $resultMsg . "<br> письмо уведомление НЕ послано" . $mail->ErrorInfo, FILE_APPEND);
					} else {
						file_put_contents("/home/bitrix/www/bitrix/prozvonlog.html", PHP_EOL . "<br>" . date("Y-m-d H:i:s") . $resultMsg . "<br> письмо уведомление послано", FILE_APPEND);
					}

				}
			} catch (Exception $e) {
				file_put_contents("/home/bitrix/www/bitrix/prozvonlog.html", PHP_EOL . "<br><hr>" . date("Y-m-d H:i:s") . " Выброшено исключение:" . $e->getMessage(), FILE_APPEND);
			}

		}
	}
}

?>
