<?php

/*
Template Name: Balance-v3
*/

spl_autoload_register(function ($class) {
    include __DIR__."/classes/$class.php";
});
include_once 'config.php';

function getSwitchIp($ipOctets, $type) {
    switch ($type) {
        case "Ethernet":
            $ip = ($ipOctets[1] < 19) ?
                sprintf('%s.0.%s.%s', $ipOctets[0], $ipOctets[1], $ipOctets[2]): 
                sprintf('%s.0.%s.%s', $ipOctets[0], $ipOctets[1]-10, $ipOctets[2]);
            break;
        case "PON":
            $ip = sprintf('10.2.2.%s', $ipOctets[1]);
            break;

        default:
            break;
    }
    return $ip;
}

function getSwitchPort($ipOctets, $type) {
    switch ($type) {
        case "Ethernet":
            $port = (substr($ipOctets[3],-1)<5) ? substr($ipOctets[3],0,-1)+0: 
                substr($ipOctets[3],0,-1)+24;
            break;
        case "PON":
            $port = ($ipOctets[3]>100) ? ($ipOctets[2][0]-1)*64+$ipOctets[3]-100 :
                ($ipOctets[2][0]-1)*64+$ipOctets[3]-10;
            break;

        default:
            break;
    }
    return $port;
}

function getContract($ip, $type) {
    switch ($type) {
        case 'PON':
        case 'Ethernet':
            $switch = getSwitchIp($ip, $type);
            $port = getSwitchPort($ip, $type);
            $query = "
SELECT is15.contractId id, c.status status, c.scid scid
FROM inv_device_15 id15
LEFT JOIN inv_device_port_subscription_15 idps15 ON idps15.deviceId=id15.id
LEFT JOIN inet_serv_15 is15 ON idps15.subscriberId=is15.id
LEFT JOIN contract c ON c.id=is15.contractId
WHERE id15.host='$switch' AND idps15.dateTo IS NULL AND idps15.port=$port
";
            $contract = BGBClass::sqlQuery($query);
            break;
        case 'Static':
            $query = "
SELECT is15.contractId id, c.status status, c.scid scid
FROM inet_serv_15 is15
LEFT JOIN contract c ON c.id=is15.contractId
WHERE is15.dateTo IS NULL AND is15.title REGEXP '^\[(\]?$ip\[)\]?$'
";
            $contract = BGBClass::sqlQuery($query);
            break;
        case 'Wireless':
            $query = "
SELECT is15.contractId id, c.status status, c.scid scid
FROM inet_serv_15 is15
LEFT JOIN contract c ON c.id=is15.contractId
WHERE is15.dateTo IS NULL AND is15.title REGEXP '^\[(\]?$ip-|-$ip\[)\]?$'
";
            $contract = BGBClass::sqlQuery($query);
            break;

        default:
            break;
    }
    return $contract;
}

function getHidenSubscriber($cid) {
    $subscriber = (BGBClass::getContractParameter($cid, 1)->value == null) ?
        BGBClass::getContractParameter($cid, 6)->value :
        BGBClass::getContractParameter($cid, 1)->value;
    $array = explode(' ', $subscriber);
    $hiden = array();
    foreach ($array as $word) {
        array_push($hiden, preg_replace('/(?<!^).*(?!$)/u', '**', $word));
    }
    return implode(' ', $hiden);
}

function getHTML($cid, $status, $scid) {
    switch ($cid) {
        case 0:
            $html = '<font color="red">Порт свободен</font>';
            break;

        default:
            $htmlTariff = BGBClass::getContractTariff($cid);
            $monthCost = BGBClass::getTariffCost($htmlTariff);
            $htmlBalance = ($scid < 1) ? BGBClass::getCurrentBalance($cid) :
                BGBClass::getCurrentBalance($scid);
            $sum = ($status == 3) ? $monthCost + $htmlBalance * (-1) : $monthCost;
            switch ($status) {
                case 0:
                    $countDays = BGBClass::getCountDays($htmlTariff, $htmlBalance);
                    $htmlStatus = "Активен. Баланса хватит примерно на $countDays д.";
                    break;
                case 3:
                    $htmlStatus = "<font color='red'>Интернет отключен за 
                        неуплату. Необходимо внести на счет минимум $sum руб.</font>";
                    break;
                case 4:
                    $htmlStatus = '<font color="red">Договор приостановлен. 
                        Для возобновления обслуживания по договору вам необходимо 
                        обратиться в телекомпанию.</font>';
                    break;

                default:
                    break;
            }
            $htmlAddress = preg_replace('/(\d{0,6}, г. Кумертау, )?(, \d* под.)?(, \d* эт\.)?/', '',
                BGBClass::getContractParameter($cid, 12)->title);
            $htmlSubscriber = getHidenSubscriber($cid);
            $htmlPayCode = ($scid < 1) ? 1000000000+$cid : 1000000000+$scid;
            $form = "<form action='/balance/online-pay/' method='post'>
                <input type=hidden name='payCode' id='payCode' value='$htmlPayCode'>
                <br><input name='step' value='0' type='hidden'/>
                <input type='submit' name='submit' value='Пополнить баланс'>
                </form>";
            $html = sprintf('<p>Абонент: %s</p><p>Адрес: %s</p><p>Статус 
                договора: %s</p><p>Тарифный план: %s</p><p>Баланс: %s</p><p>Код 
                для оплаты (лицевой счет) %s</p>%s', $htmlSubscriber,
                $htmlAddress, $htmlStatus, $htmlTariff, $htmlBalance,
                $htmlPayCode, $form);
            break;
    }
    return $html;
}

$ip = filter_input(INPUT_GET, 'ip') ?? filter_input(INPUT_SERVER, 'REMOTE_ADDR');
$ipOctets = explode('.', $ip);
$ipOctet3 = implode('.', array_slice($ipOctets, 0, 3));

switch ($ipOctet3) {
    case '10.11.95':
    case '10.11.101':
    case '10.11.107':
    case '10.11.149':
    case '10.12.30':
    case '10.12.47':
        $type = 'Wireless';
        $contract = getContract($ip, $type);
        $html = getHTML($contract->id, $contract->status, $contract->scid);
        break;
    case '195.191.78':
    case '176.117.0':
        $type = 'Static';
        $contract = getContract($ip, $type);
        $html = getHTML($contract->id, $contract->status, $contract->scid);
        break;
    case (preg_match('/10\.[1-2][0-3]\.[1-9][0-9]?[0-9]?/', $ipOctet3) ? true : false):
        $type = 'Ethernet';
        $contract = getContract($ipOctets, $type);
        $html = getHTML($contract->id, $contract->status, $contract->scid);
        break;
    case(preg_match('/10\.1[0-2][0-9]\.[1-4]0?/', $ipOctet3) ? true : false):
        $type = 'PON';
        $contract = getContract($ipOctets, $type);
        $html = getHTML($contract->id, $contract->status, $contract->scid);
        break;
    case '10.2.2':
        $html = 'Есть подозрение, что вы связаны с полубогами и не должны демонстрировать 
            усталость ;-)<br><br>Just relax, please...<br><br><iframe width="640" 
            height="480" src="https://www.youtube.com/embed/LRP8d7hhpoQ" 
            title="Oh My God!" frameborder="0" allow="accelerometer; autoplay; clipboard-write;
            encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
        break;

    default:
        $html = 'Нам не удалось автоматически распознать Вас в качестве абонента нашей телекомпании.<br>
Возможные причины и как исправить:<br>
- Вы не являетесь нашим абонентом. <a href="/net/subscribe/">Подключайтесь!</a><br>
- В Вашем браузере включен режим ускорения (Turbo). Отключите ускорители и VPN.<br>
- Вы зашли на страницу не через нашу сеть. Войдите на страницу из нашей сети или воспользуйтесь 
<a href="https://client.fialka.tv">Личным кабинетом</a><br>
<br>
Связаться с технической поддержкой можно:<br>
- Через данный сайт: в правом нижнем углу мерцает виджет.<br>
- Через <a href="https://t.me/Fialka_LLC_bot">Телеграм-бот</a>.<br>
- Через <a href="https://vk.com/im?sel=-105081718">официальную группу вконтакте</a>.<br>
- Через <a href="https://ok.ru/group/70000001422570/messages">официальную группу одноклассников</a>.<br>
- По телефонам: <a href="tel:79373444320">+7(937)344-43-20</a>, 
<a href="tel:79373443113">+7(937)344-31-13</a>, 
<a href="tel:73476144320">+7(34761)4-43-20</a>, 
<a href="tel:73476143113">+7(34761)4-31-13</a>, 
<a href="tel:73476144273">+7(34761)4-42-73</a>';
        break;
}
header('Content-Type: text/html; charset=UTF-8');
get_header();
global $woo_options;
woo_content_before();
echo "
<div id='title-container'><h1 class='title col-full'>Баланс</h1></div>
<div id='content' class='page col-full'>
<div id='main' class='col-left'>
<div class='entry'>
<div class='woo-sc-box normal rounded full'>
<p>Ваш IP-адрес: $ip</p>
<p>$html</p></div></div>
";
woo_pagenav();
wp_reset_query();
echo '</div>';
get_sidebar();
echo '</div>';
get_footer();
