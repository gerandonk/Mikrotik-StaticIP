<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 *
 * This is Core, don't modification except you want to contribute
 * better create new plugin
 **/

use PEAR2\Net\RouterOS;

class MikrotikStatic
{
    // show Description
    function description()
    {
        return [
            'title' => 'Mikrotik STATIC',
            'description' => 'To handle connection between PHPNuxBill with Mikrotik STATIC',
            'author' => 'Gerandonk',
            'url' => [
                'Github' => 'https://github.com/gerandonk/Mikrotik-StaticIP',
                'Telegram' => 'https://t.me/sklitinov',
                'Donate' => 'https://paypal.me/sklitinov'
            ]
        ];
    }

    function add_customer($customer, $plan)
    {
        global $isChangePlan;
        $mikrotik = $this->info($plan['routers']);
        $client = $this->getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
        $cid = self::getIdByCustomer($customer, $client);
        $isExp = ORM::for_table('tbl_plans')->select("id")->where('plan_expired', $plan['id'])->find_one();
        if (empty($cid)) {
            //customer not exists, add it
            $this->addStaticUser($client, $plan, $customer, $isExp);
        }else{
			$bw = ORM::for_table("tbl_bandwidth")->find_one($plan['id_bw']);
			if ($bw['rate_down_unit'] == 'Kbps') {
				$unitdown = 'K';
			} else {
				$unitdown = 'M';
			}
			if ($bw['rate_up_unit'] == 'Kbps') {
				$unitup = 'K';
			} else {
				$unitup = 'M';
			}
			$rate = $bw['rate_up'] . $unitup . "/" . $bw['rate_down'] . $unitdown;
			if(!empty(trim($bw['burst']))){
				$rate .= ' '.$bw['burst'];
			}
            //$setRequest = new RouterOS\Request('/ip/dhcp-server/lease/set');
			$setRequest = new RouterOS\Request('/queue/simple/set');
            $setRequest->setArgument('numbers', $cid);
            if (!empty($customer['pppoe_username'])) {
				$setRequest->setArgument('name', $customer['pppoe_username']);
			} else {
				$setRequest->setArgument('name', $customer['username']);
			}
			$setRequest->setArgument('target', $customer['pppoe_ip']);
			$setRequest->setArgument('max-limit', $rate);
			$setRequest->setArgument('comment', $plan['name_plan'] . ' | ' . $customer['fullname'] . ' | ' . $customer['email'] . ' | ' . implode(', ', User::getBillNames($customer['id'])));
			$client->sendSync($setRequest);
			if (!$isExp){
				$this->removeIpFromAddressList($client, $customer['pppoe_ip']);
			}
        }
    }

    function remove_customer($customer, $plan)
    {
        $mikrotik = $this->info($plan['routers']);
        $client = $this->getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
        if (!empty($plan['plan_expired'])) {
            $p = ORM::for_table("tbl_plans")->find_one($plan['plan_expired']);
            if($p){
				$this->add_customer($customer, $p);
                $this->addIpToAddressList($client, $customer['pppoe_ip'], $listName = 'Expired', $customer['username']);
                return;
            }
        }
		$this->addIpToAddressList($client, $customer['pppoe_ip'], $listName = 'Expired', $customer['username']);
        $this->removeStaticUser($client, $customer['username']);
        if (!empty($customer['pppoe_username'])) {
            $this->removeStaticUser($client, $customer['pppoe_username']);
        }
    }

    // customer change username
    public function change_username($plan, $from, $to)
    {
        $mikrotik = $this->info($plan['routers']);
        $client = $this->getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
        //check if customer exists
        $printRequest = new RouterOS\Request('/queue/simple/print');
        $printRequest->setQuery(RouterOS\Query::where('name', $from));
        $cid = $client->sendSync($printRequest)->getProperty('.id');
        if (!empty($cid)) {
            $setRequest = new RouterOS\Request('/queue/simple/set');
            $setRequest->setArgument('numbers', $cid);
            $setRequest->setArgument('name', $to);
            $client->sendSync($setRequest);
        }
    }

    function add_plan($plan)
    {
    }

    /**
     * Function to ID by username from Mikrotik
     */
    function getIdByCustomer($customer, $client){
        $printRequest = new RouterOS\Request('/queue/simple/print');
        $printRequest->setQuery(RouterOS\Query::where('name', $customer['username']));
        $id = $client->sendSync($printRequest)->getProperty('.id');
        if(empty($id)){
            if (!empty($customer['pppoe_username'])) {
                $printRequest = new RouterOS\Request('/queue/simple/print');
                $printRequest->setQuery(RouterOS\Query::where('name', $customer['pppoe_username']));
                $id = $client->sendSync($printRequest)->getProperty('.id');
            }
        }
        return $id;
    }

    function update_plan($old_name, $new_plan)
    {
    }

    function remove_plan($plan)
    {
    }

    function add_pool($pool){
        global $_app_stage;
        if ($_app_stage == 'demo') {
            return null;
        }
        $mikrotik = $this->info($pool['routers']);
        $client = $this->getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
        $addRequest = new RouterOS\Request('/ip/pool/add');
        $client->sendSync(
            $addRequest
                ->setArgument('name', $pool['pool_name'])
                ->setArgument('ranges', $pool['range_ip'])
        );
    }

    function update_pool($old_pool, $new_pool){
        global $_app_stage;
        if ($_app_stage == 'demo') {
            return null;
        }
        $mikrotik = $this->info($new_pool['routers']);
        $client = $this->getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
        $printRequest = new RouterOS\Request(
            '/ip pool print .proplist=.id',
            RouterOS\Query::where('name', $old_pool['pool_name'])
        );
        $poolID = $client->sendSync($printRequest)->getProperty('.id');
        if (empty($poolID)) {
            $this->add_pool($new_pool);
        } else {
            $setRequest = new RouterOS\Request('/ip/pool/set');
            $client->sendSync(
                $setRequest
                    ->setArgument('numbers', $poolID)
                    ->setArgument('name', $new_pool['pool_name'])
                    ->setArgument('ranges', $new_pool['range_ip'])
            );
        }
    }

    function remove_pool($pool){
        global $_app_stage;
        if ($_app_stage == 'demo') {
            return null;
        }
        $mikrotik = $this->info($pool['routers']);
        $client = $this->getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
        $printRequest = new RouterOS\Request(
            '/ip pool print .proplist=.id',
            RouterOS\Query::where('name', $pool['pool_name'])
        );
        $poolID = $client->sendSync($printRequest)->getProperty('.id');
        $removeRequest = new RouterOS\Request('/ip/pool/remove');
        $client->sendSync(
            $removeRequest
                ->setArgument('numbers', $poolID)
        );
    }


    function online_customer($customer, $router_name)
    {
        $mikrotik = $this->info($router_name);
        $client = $this->getClient($mikrotik['ip_address'], $mikrotik['username'], $mikrotik['password']);
        $printRequest = new RouterOS\Request(
            '/queue simple print',
            RouterOS\Query::where('name', $customer['username'])
        );
        return $client->sendSync($printRequest)->getProperty('.id');
    }

    function info($name)
    {
        return ORM::for_table('tbl_routers')->where('name', $name)->find_one();
    }

    function getClient($ip, $user, $pass)
    {
        global $_app_stage;
        if ($_app_stage == 'demo') {
            return null;
        }
        $iport = explode(":", $ip);
        return new RouterOS\Client($iport[0], $user, $pass, ($iport[1]) ? $iport[1] : null);
    }

    function removeStaticUser($client, $username)
    {
        global $_app_stage;
        if ($_app_stage == 'demo') {
            return null;
        }
		$printRequest = new RouterOS\Request('/queue/simple/print');
        //$printRequest->setArgument('.proplist', '.id');
        $printRequest->setQuery(RouterOS\Query::where('name', $username));
        $id = $client->sendSync($printRequest)->getProperty('.id');
        $removeRequest = new RouterOS\Request('/queue/simple/remove');
        $removeRequest->setArgument('numbers', $id);
        $client->sendSync($removeRequest);
    }

    function addStaticUser($client, $plan, $customer, $isExp = false)
    {
		$bw = ORM::for_table("tbl_bandwidth")->find_one($plan['id_bw']);
        if ($bw['rate_down_unit'] == 'Kbps') {
            $unitdown = 'K';
        } else {
            $unitdown = 'M';
        }
        if ($bw['rate_up_unit'] == 'Kbps') {
            $unitup = 'K';
        } else {
            $unitup = 'M';
        }
        $rate = $bw['rate_up'] . $unitup . "/" . $bw['rate_down'] . $unitdown;
        if(!empty(trim($bw['burst']))){
            $rate .= ' '.$bw['burst'];
        }
		if (!$isExp) {
			$this->removeIpFromAddressList($client, $customer['pppoe_ip']);
		}
		$setRequest = new RouterOS\Request('/queue/simple/add');
		if (!empty($customer['pppoe_username'])) {
			$setRequest->setArgument('name', $customer['pppoe_username']);
		} else {
			$setRequest->setArgument('name', $customer['username']);
		}
        $setRequest->setArgument('target', $customer['pppoe_ip']);
		$setRequest->setArgument('max-limit', $rate);
        $setRequest->setArgument('comment', $plan['name_plan'] . ' | ' . $customer['fullname'] . ' | ' . $customer['email'] . ' | ' . implode(', ', User::getBillNames($customer['id'])));
        $client->sendSync($setRequest);
    }

    function removeStaticActive($client, $username)
    {
    }

    function getIpStaticUser($client, $username)
    {
    }

    function addIpToAddressList($client, $ip, $listName, $comment = '')
    {
        global $_app_stage;
        if ($_app_stage == 'demo') {
            return null;
        }
        $addRequest = new RouterOS\Request('/ip/firewall/address-list/add');
        $client->sendSync(
            $addRequest
                ->setArgument('address', $ip)
                ->setArgument('comment', $comment)
                ->setArgument('list', $listName)
        );
    }

    function removeIpFromAddressList($client, $ip)
    {
        global $_app_stage;
        if ($_app_stage == 'demo') {
            return null;
        }
        $printRequest = new RouterOS\Request(
            '/ip firewall address-list print .proplist=.id',
            RouterOS\Query::where('address', $ip)
        );
        $id = $client->sendSync($printRequest)->getProperty('.id');
        $removeRequest = new RouterOS\Request('/ip/firewall/address-list/remove');
        $client->sendSync(
            $removeRequest
                ->setArgument('numbers', $id)
        );
    }
}
