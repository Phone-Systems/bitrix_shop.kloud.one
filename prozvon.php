<?
define('STOP_STATISTICS', true);
require_once ($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
$GLOBALS['APPLICATION']->RestartBuffer();

//"ключ шифрования". должен совпадать со соответсвующей строкой в админке ЛК на стороне сервера прозвона
$secret = "bigSecretString";

$orderID 		= intval($_REQUEST["orderid"]);
$prozvon_result = intval($_REQUEST["result"]);
$token 			= strtoupper($_REQUEST["token"]);

// сюда пишем лог запросов от сервера прозваона с результатами прозвона. если не нужен - закомментировать
file_put_contents("/home/bitrix/www/log_p.txt", PHP_EOL .date('Y-m-d H:i:s'). " Id = " . $orderID . " result = " . $prozvon_result, FILE_APPEND);

// высчитываем контрольную строку/токен, для проверки того, что запрос пришел от сервера прозвона
$crc			= strtoupper(md5($secret.$orderID.date("dmY")));

// если токен не совпадает с расчетным - ошибка "bad request"
if($crc != $token) {
	echo "bad request ";

	// это раскомментировать для отладки, чтобы не писать правильный токен в лог
	//file_put_contents("/home/bitrix/www/log_p.txt", PHP_EOL . "bad request token = $token must be $crc", FILE_APPEND);

	// логируем факт не верного токена в запросе
	file_put_contents("/home/bitrix/www/log_p.txt", PHP_EOL . "bad request token = $token", FILE_APPEND);

	exit();
}

CModule::IncludeModule( 'sale' );

// если прозвон успешный и клиент согласен
if ($prozvon_result == 1) {
	$orders = CSaleOrder::GetList(Array("ID" => "DESC") , Array("ID" => $orderID) , false, false, Array("*"));
	if ($order = $orders->Fetch()) { //если есть такой заказ
		if (CSaleOrder::StatusOrder($orderID, "C")) { // меняем статус на С "подтвержден, ожидает оплаты"
			$result = "Заказ номер " . $orderID . " прозвон успешен, статус сменен на 'подтвержден, ожидает оплаты'";
			$resultCode = 1;
		} else {
			$result = "Заказ номер " . $orderID . " прозвон успешен, ОШИБКА смены статуса на 'подтвержден, ожидает оплаты'";
			$resultCode = 0;
		}
	} else { // нет такого заказа
		$result = "Попытка смены статуса не найденного заказа номер " . $orderID . " на 'подтвержден, ожидает оплаты'";
		$resultCode = 0;
	}
} elseif ($prozvon_result == 2) { // если прозвон успешный и клиент не согласен
	$orders = CSaleOrder::GetList(Array("ID" => "DESC") , Array("ID" => $orderID) , false, false, Array("*"));
	if ($order = $orders->Fetch()) { //если есть такой заказ
		if (CSaleOrder::StatusOrder($orderID, "X")) { // меняем статус на X "Отменен"
			$result = "Заказ номер " . $orderID . " прозвон успешен, статус сменен на 'Отменен'";
			$resultCode = 1;
		} else {
			$result = "Заказ номер " . $orderID . " прозвон успешен, ОШИБКА смены статуса на 'Отменен'";
			$resultCode = 0;
		}
	} else { // нет такого заказа
		$result = "Попытка смены статуса не найденного заказа номер " . $orderID . " на 'Отменен'";
		$resultCode = 0;
	}
} else { // прозвон не удался
	// сообщаем на почту об этом
	$result = "Заказ номер " . $orderID . " ОШИБКА прозвона, статус НЕ сменен";
	$resultCode = 1;
}

// логируем результат
file_put_contents("/home/bitrix/www/log_p.txt", PHP_EOL . $result, FILE_APPEND);

// подключаем библиотеку PHPMailer, если нет - можно и просто mail использовать
require './pmailer/PHPMailerAutoload.php';

$mail = new PHPMailer;
$mail->CharSet = 'UTF-8';
$mail->setFrom('sales@____.ru', 'Интернет-магазин ____.Ru');
$mail->addReplyTo('sales@____.ru', 'Интернет-магазин ____.Ru');

$mail->addAddress('sales@____.ru', 'Интернет-магазин ____.Ru');

$mail->Subject = 'Отчет по прозвону заказа ' . $orderID . '. Интернет-магазин ____.Ru';
$mail->msgHTML($result);
$mail->AltBody = strip_tags($result);
if (!$mail->send()) {
	// echo "Mailer Error: " . $mail->ErrorInfo;
}
else {
	// echo " Письмо отправлено. ";
}

// выводим результат работы скрипта - его увидит сервер прозвона. 1 - все хорошо. 0 - все плохо.
echo $resultCode;
?>
