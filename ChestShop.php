<?php

/*
 __PocketMine Plugin__
name=ChestShop
description=You can open your chest shop and purchase from others' chest shop.
version=1.8.2
author=MinecrafterJPN
class=ChestShop
apiversion=11
*/

class ChestShop implements Plugin
{
	const CONFIG_POCKETMONEY = 0b01;
	const CONFIG_ECONOMY = 0b10;

	private $api, $db, $config, $blocks, $blocks2, $items, $items2;

	public function __construct(ServerAPI $api, $server = false)
	{
		$this->api = $api;	
		$this->blocks = array();
		$this->blocks2 = array();
		$this->items = array();
		$this->items2 = array();
	}

	public function init()
	{
		if (file_exists("./plugins/PocketMoney.php")) {
			$this->config['moneyplugin'] = self::CONFIG_POCKETMONEY;
		} elseif (file_exists("./plugins/EconomyAPI.php")) {
			$this->config['moneyplugin'] = self::CONFIG_ECONOMY;
		} else {
			console(FORMAT_RED . "[ChestShop][Error] PocketMoney or €¢onom¥$ has not been loaded.");
			$this->api->console->defaultCommands("stop", array(), false, false);
		}
		foreach (Block::$class as $id => $name) {
			$this->blocks[$id] = strtolower($name);
			$this->blocks2[$id] = strtolower(substr($name, 0, -5)); //fooBlock -> foo
		}
		foreach (Item::$class as $id => $name) {
			$this->items[$id] = strtolower($name);
			$this->items2[$id] = strtolower(substr($name, 0, -4)); //barItem -> bar
		}
		$this->api->addHandler("tile.update", array($this, "eventHandler"));
		$this->api->addHandler("player.block.touch", array($this, "eventHandler"));
		$this->loadDB();
	}

	public function eventHandler($data, $event)
	{
		switch ($event) {
			case "tile.update":
				if ($data->class === TILE_SIGN) {
					$shopOwner = $data->data['creator'];
					$saleNum = $data->data['Text2'];
					$price = $data->data['Text3'];
					$productID = $this->isItem($data->data['Text4']);

					if ($data->data['Text1'] !== "") break;
					if (!is_numeric($saleNum) or $saleNum <= 0) break;
					if (!is_numeric($price) or $price < 0) break;
					if ($productID === false) break;

					$chest = $this->getSideChest($data);
					if ($chest === false) break;
					if (strlen($shopOwner) > 15) $shopOwner = substr($shopOwner, 0, 15);

					$data->setText($shopOwner, "Amount:$saleNum", "Price:$price", $data->data['Text4']);
					$this->db->exec("INSERT INTO ChestShop (shopOwner, saleNum, price, productID, signX, signY, signZ, chestX, chestY, chestZ) VALUES ('$shopOwner', $saleNum, $price, $productID, $data->x, $data->y, $data->z, $chest->x, $chest->y, $chest->z)");
				}
				break;
			case "player.block.touch":
				if (($tile = $this->api->tile->get(new Position($data['target']->x, $data['target']->y, $data['target']->z, $data['target']->level))) === false) break;
				switch ($tile->class) {
					case TILE_SIGN:
						switch ($data['type']) {
							case "place":
								if (($shopInfo = $this->db->query("SELECT * FROM ChestShop WHERE signX = {$data['target']->x} AND signY = {$data['target']->y} AND signZ = {$data['target']->z}")->fetchArray(SQLITE3_ASSOC)) === false) break;
								if ($shopInfo['shopOwner'] === $data['player']->username) {
									$this->api->chat->sendTo(false, "[ChestShop][Error] Cannot purchase from your own shop", $data['player']->username);
									break;
								}
								$buyerMoney = false;
								if ($this->config['moneyplugin'] & self::CONFIG_POCKETMONEY) {
									$buyerMoney = PocketMoney::getMoney($data['player']->username);
								} elseif ($this->config['moneyplugin'] & self::CONFIG_ECONOMY) {
									$buyerMoney = $this->api->economy->getMoney()[$data['player']->username];
								}
								if ($buyerMoney === false) {
									$this->api->chat->sendTo(false, "[ChestShop][Error] Cannot get your money data!", $data['player']->username);
									break;
								}
								if ($buyerMoney < $shopInfo['price']) {
									$this->api->chat->sendTo(false, "[ChestShop][Error] Your money is not enough", $data['player']->username);
									break;
								}
								$chest = $this->api->tile->get(new Position($shopInfo['chestX'], $shopInfo['chestY'], $shopInfo['chestZ'], $data['target']->level));
								$itemNum = 0;
								for ($i = 0; $i < CHEST_SLOTS; $i++) {
									$item = $chest->getSlot($i);
									if ($item->getID() === $shopInfo['productID']) {
										$itemNum += $item->count;
									}
								}
								$productName = isset($this->blocks[$shopInfo['productID']]) ? $this->blocks[$shopInfo['productID']] : $this->items[$shopInfo['productID']];
								if ($itemNum < $shopInfo['saleNum']) {
									$this->api->chat->sendTo(false, "[ChestShop] This shop is out of stock!", $data['player']->username);
									$this->api->chat->sendTo(false, "[ChestShop] Your ChestShop is out of stock! Replenish $productName!", $shopInfo['shopOwner']);
									break;
								}
								$this->api->block->commandHandler("give", array($data['player']->username, $shopInfo['productID'], $shopInfo['saleNum']), $data['player'], false);
								$tmpNum = $shopInfo['saleNum'];
								for ($i = 0; $i < CHEST_SLOTS; $i++) {
									$item = $chest->getSlot($i);
									if ($item->getID() === $shopInfo['productID']) {
										if ($item->count <= $tmpNum) {
											$chest->setSlot($i, BlockAPI::getItem(AIR, 0, 0));
											$tmpNum -= $item->count;
										} else {
											$count = $item->count - $tmpNum;
											$chest->setSlot($i, BlockAPI::getItem($item->getID(), 0, $count));
											break;
										}
									}
								}
								if ($this->config['moneyplugin'] & self::CONFIG_POCKETMONEY) {
									PocketMoney::grantMoney($data['player']->username, -$shopInfo['price']);
									PocketMoney::grantMoney($shopInfo['shopOwner']->username, $shopInfo['price']);
								} elseif($this->config['moneyplugin'] & self::CONFIG_ECONOMY) {
									$this->api->economy->useMoney($data['player']->username, $shopInfo['price']);
									$this->api->economy->takeMoney($shopInfo['shopOwner'], $shopInfo['price']);
								}
								$this->api->chat->sendTo(false, "[ChestShop] Completed the transaction.", $data['player']->username);
								$this->api->chat->sendTo(false, "[ChestShop] {$data['player']->username} purchased your $productName: {$shopInfo['price']}PM", $shopInfo['shopOwner']);
								break;
							case "break":
								$result = $this->db->query("SELECT shopOwner FROM ChestShop WHERE signX = {$data['target']->x} AND signY = {$data['target']->y} AND signZ = {$data['target']->z}")->fetchArray(SQLITE3_ASSOC);
								if ($result !== false) {
									if ($result['shopOwner'] !== $data['player']->username) {
										$this->api->chat->sendTo(false, "[ChestShop] This sign has been protected", $data['player']->username);
										return false;
									} else {
										$this->db->exec("DELETE FROM ChestShop WHERE {$data['target']->x} AND signY = {$data['target']->y} AND signZ = {$data['target']->z}");
										$this->api->chat->sendTo(false, "[ChestShop] Your ChestShop was closed", $data['player']->username);
										break;
									}
								}
								break;
						}
						break;
					case TILE_CHEST:
						$result = $this->db->query("SELECT shopOwner FROM ChestShop WHERE chestX = {$data['target']->x} AND chestY = {$data['target']->y} AND chestZ = {$data['target']->z}")->fetchArray(SQLITE3_ASSOC);
						if ($result === false) break;
						if ($result['shopOwner'] !== $data['player']->username) {
							$this->api->chat->sendTo(false, "[ChestShop] You are not the owner of this chest", $data['player']->username);
							return false;
						} elseif ($data['type'] === "break") {
							$this->db->exec("DELETE FROM ChestShop WHERE chestX = {$data['target']->x} AND chestY = {$data['target']->y} AND chestZ = {$data['target']->z}");
							$this->api->chat->sendTo(false, "[ChestShop] Your ChestShop was closed", $data['player']->username);
						}
						break;
				}
		}
	}

	private function loadDB()
	{
		$this->db = new SQLite3($this->api->plugin->configPath($this) . "ChestShop.sqlite3");
		$this->db->exec(
				"CREATE TABLE IF NOT EXISTS ChestShop(
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				shopOwner TEXT NOT NULL,
				saleNum INTEGER NOT NULL,
				price INTEGER NOT NULL,
				productID INTEGER NOT NULL,
				signX INTEGER NOT NULL,
				signY INTEGER NOT NULL,
				signZ INTEGER NOT NULL,
				chestX INTEGER NOT NULL,
				chestY INTEGER NOT NULL,
				chestZ INTEGER NOT NULL
		)"
		);
	}

	private function getSideChest($data)
	{
		$item = $data->level->getBlock(new Vector3($data->x + 1, $data->y, $data->z));
		if ($item->getID() === CHEST) return $item;
		$item = $data->level->getBlock(new Vector3($data->x - 1, $data->y, $data->z));
		if ($item->getID() === CHEST) return $item;
		$item = $data->level->getBlock(new Vector3($data->x, $data->y, $data->z + 1));
		if ($item->getID() === CHEST) return $item;
		$item = $data->level->getBlock(new Vector3($data->x, $data->y, $data->z - 1));
		if ($item->getID() === CHEST) return $item;
		return false;
	}

	private function isItem($item)
	{
		$item = strtolower($item);
		if (isset($this->blocks[$item])) return $item;
		if (isset($this->items[$item])) return $item;
		if (($id = array_search($item, $this->blocks)) !== false) return $id;
		if (($id = array_search($item, $this->blocks2)) !== false) return $id;
		if (($id = array_search($item, $this->items)) !== false) return $id;
		if (($id = array_search($item, $this->items2)) !== false) return $id;
		return false;
	}

	public function __destruct()
	{
	}
}