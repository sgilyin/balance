<?php

/**
 * Description of BGBClass
 *
 * @author Sergey Ilyin <developer@ilyins.ru>
 */
class BGBClass {
    private static function execute($param) {
        $url = 'http://' . BGB_HOST . ':8080/bgbilling/executer/json/' . $param->package . '/' . $param->class;
        $post['method'] = $param->method;
        $post['user']['user'] = BGB_USER;
        $post['user']['pswd'] = BGB_PASSWORD;
        $post['params'] = $param->params;
        $json = json_decode(curlClass::executeRequest('POST', $url, json_encode($post), false));
        return $json;
    }

    public static function getCurrentBalance($cid) {
        $param = new stdClass();
        $param->package = 'ru.bitel.bgbilling.kernel.contract.balance';
        $param->class = 'BalanceService';
        $param->method = 'contractBalanceGet';
        $param->params['contractId'] = $cid;
        $param->params['year'] = date('Y');
        $param->params['month'] = date('n');
        $json = self::execute($param);
        $balance = round($json->data->return->incomingSaldo + $json->data->return->payments - $json->data->return->accounts - $json->data->return->charges, 2);
        return $balance;
    }

    public static function getContractParameter($cid, $paramId) {
        $param = new stdClass();
        $param->package = 'ru.bitel.bgbilling.kernel.contract.api';
        $param->class = 'ContractService';
        $param->method = 'contractParameterGet';
        $param->params['contractId'] = $cid;
        $param->params['parameterId'] = $paramId;
        $json = self::execute($param);
        return $json->data->return;
    }

    public static function getContractTariff($cid) {
        $param = new stdClass();
        $param->package = 'ru.bitel.bgbilling.kernel.contract.api';
        $param->class = 'ContractTariffService';
        $param->method = 'contractTariffEntryList';
        $param->params['contractId'] = $cid;
        $param->params['date'] = date('Y-m-d');
        $param->params['entityMid'] = -1;
        $param->params['entityId'] = -1;
        $json = self::execute($param);
        $tariff = array();
        for ($i = 0; $i < count($json->data->return); $i++) {
            array_push($tariff, $json->data->return[$i]->title);
        }
        return implode(", ", $tariff);
    }

    public static function sqlQuery($query) {
        $url = 'http://' . BGB_HOST . ':8080/bgbilling/executer?user='.BGB_USER.
                '&pswd='.BGB_PASSWORD.'&module=sqleditor&base=main&action=SQLEditor&sql='. urlencode($query);
        $result = simplexml_load_file($url);
        $contract = new stdClass();
        $contract->id = intval($result->table->data->row['row0']);
        $contract->status = intval($result->table->data->row['row1']);
        $contract->scid = intval($result->table->data->row['row2']);
        return $contract;
    }

    public static function getTariffCost($tariff) {
        $cost = 0;
        preg_match_all('/\d*(?=Р)/', $tariff, $matches);
        foreach ($matches[0] as $tariffCost) {
            $cost += intval($tariffCost);
        }
        return $cost;
    }

    public static function getCountDays($tariff, $balance) {
        $cost = self::getTariffCost($tariff);
        return floor($balance / ($cost / intval(date("t"))) - 1);
    }
}
