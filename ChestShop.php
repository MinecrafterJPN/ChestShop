<?php

/*
 __PocketMine Plugin__
name=ChestShop
description=Open your chest shop
version=1.9dev
author=MinecrafterJPN
class=ChestShop
apiversion=12
*/

class ChestShop implements Plugin
{
	const CONFIG_POCKETMONEY = 0b01;
	const CONFIG_ECONOMY = 0b10;

	private $api, $db, $config, $system;

	public function __construct(ServerAPI $api, $server = false)
	{
		$this->api = $api;
	}

	public function init()
	{
		if (file_exists(DATA_PATH."plugins/PocketMoney.php")) {
			$this->config['moneyplugin'] = self::CONFIG_POCKETMONEY;
		} elseif (file_exists(DATA_PATH."plugins/EconomyAPI.php")) {
			$this->config['moneyplugin'] = self::CONFIG_ECONOMY;
		} else {
			console(FORMAT_RED."[ChestShop][Error] PocketMoney or €¢onom¥$ has not been loaded.");
			$this->api->console->defaultCommands("stop", array(), false, false);
		}
		$this->api->addHandler("tile.update", array($this, "eventHandler"));
		$this->api->addHandler("player.block.touch", array($this, "eventHandler"));
		$this->api->console->register("id", "Search block ID", array($this, "commandHandler"));
		$this->loadDB();
		$this->system = new Config($this->api->plugin->configPath($this) . "system.yml", CONFIG_YAML, array("insert_productMeta" => false));
		if (!$this->system->get("insert_productMeta")) $this->insertProductMeta();
	}

	public function commandHandler($cmd, $args, $issuer, $alias)
	{
		$output .= "";
		$name = array_shift($args);
		foreach (Block::$class as $key => $value) {
			$value = substr($value, 0, -5);
			if (strpos(strtolower($value), strtolower($name)) !== false) $output .= "[ChestShop] ID:$key $value\n";
		}
		foreach (Item::$class as $key => $value) {
			$value = substr($value, 0, -4);
			if (strpos(strtolower($value), strtolower($name)) !== false) $output .= "[ChestShop] ID:$key $value\n";
		}
		if ($output === "") $output .= "[ChestShop] Not found\n";
		return $output;
	}

	public function eventHandler($data, $event)
	{
		switch ($event) {
			case "tile.update":
				if ($data->class === TILE_SIGN) {
					$shopOwner = $data->data['creator'];
					$saleNum = $data->data['Text2'];
					$price = $data->data['Text3'];
					$productData = explode(":", $data->data['Text4']);
					$pID = $productData[0];
					$pMeta = isset($productData[1]) ? $productData[1] : 0;
					$pID = $this->isItem($pID);

					if ($data->data['Text1'] !== "") break;
					if (!is_numeric($saleNum) or $saleNum <= 0) break;
					if (!is_numeric($price) or $price < 0) break;
					if ($pID === false) break;
					$chest = $this->getSideChest($data);
					if ($chest === false) break;

					$productName = isset(Block::$class[$pID]) ? substr(Block::$class[$pID], 0, -5) : substr(Item::$class[$pID], 0, -4);
					$data->setText($shopOwner, "Amount:$saleNum", "Price:$price", "$productName:$pMeta");
					$this->db->exec("INSERT INTO ChestShop (shopOwner, saleNum, price, productID, productMeta, signX, signY, signZ, chestX, chestY, chestZ) VALUES ('$shopOwner', $saleNum, $price, $pID, $pMeta, $data->x, $data->y, $data->z, $chest->x, $chest->y, $chest->z)");
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
								$pID = $shopInfo['productID'];
								$pMeta = $shopInfo['productMeta'];
								for ($i = 0; $i < CHEST_SLOTS; $i++) {
									$item = $chest->getSlot($i);
									if ($item->getID() === $pID and $item->getMetadata() === $pMeta) $itemNum += $item->count;
								}
								if ($itemNum < $shopInfo['saleNum']) {
									$this->api->chat->sendTo(false, "[ChestShop] This shop is out of stock!", $data['player']->username);
									$this->api->chat->sendTo(false, "[ChestShop] Your ChestShop is out of stock! Replenish ID:${pID}!", $shopInfo['shopOwner']);
									break;
								}
								$this->api->block->commandHandler("give", array($data['player']->username, "{$shopInfo['productID']}:{$shopInfo['productMeta']}", $shopInfo['saleNum']), $data['player'], false);
								$tmpNum = $shopInfo['saleNum'];
								for ($i = 0; $i < CHEST_SLOTS; $i++) {
									$item = $chest->getSlot($i);
									if ($item->getID() === $pID and $item->getMetadata() === $pMeta) {
										if ($item->count <= $tmpNum) {
											$chest->setSlot($i, BlockAPI::getItem(AIR, 0, 0));
											$tmpNum -= $item->count;
										} else {
											$count = $item->count - $tmpNum;
											$chest->setSlot($i, BlockAPI::getItem($item->getID(), $pMeta, $count));
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
								$this->api->chat->sendTo(false, "[ChestShop] {$data['player']->username} purchased ID:{$pID}: {$shopInfo['price']}PM", $shopInfo['shopOwner']);
								break;
							case "break":
								$result = $this->db->query("SELECT shopOwner FROM ChestShop WHERE signX = {$data['target']->x} AND signY = {$data['target']->y} AND signZ = {$data['target']->z}")->fetchArray(SQLITE3_ASSOC);
								if ($result !== false) {
									if ($result['shopOwner'] !== $data['player']->username) {
										$this->api->chat->sendTo(false, "[ChestShop] This sign has been protected", $data['player']->username);
										return false;
									} else {
										$this->db->exec("DELETE FROM ChestShop WHERE {$data['target']->x} AND signY = {$data['target']->y} AND signZ = {$data['target']->z}");
										$this->api->chat->sendTo(false, "[ChestShop] You closed your ChestShop", $data['player']->username);
										break;
									}
								}
								break;
						}
						break;
					case TILE_CHEST:
						$result = $this->db->query("SELECT shopOwner FROM ChestShop WHERE chestX = {$data['target']->x} AND chestY = {$data['target']->y} AND chestZ = {$data['target']->z}")->fetchArray(SQLITE3_ASSOC);
						if ($result !== false) {
							if ($result['shopOwner'] !== $data['player']->username) {
							$this->api->chat->sendTo(false, "[ChestShop] You are not the owner of this chest", $data['player']->username);
							return false;
							} elseif ($data['type'] === "break") {
								$this->db->exec("DELETE FROM ChestShop WHERE chestX = {$data['target']->x} AND chestY = {$data['target']->y} AND chestZ = {$data['target']->z}");
								$this->api->chat->sendTo(false, "[ChestShop] You closed your ChestShop", $data['player']->username);
							}
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
				productMeta INTEGER NOT NULL,
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

	private function isItem($id)
	{
		if (isset(Block::$class[$id])) return $id;
		if (isset(Item::$class[$id])) return $id;
		return false;
	}

	private function insertProductMeta()
	{
		$this->system->set("insert_productMeta", true);
		$this->system->save();
		$columns = $this->db->query('PRAGMA table_info(ChestShop)');
		while ($column = $columns->fetchArray(SQLITE3_ASSOC)) {
			if ($column['name'] === "productMeta") return;
		}
		$this->db->exec("ALTER TABLE ChestShop ADD COLUMN productMeta INTEGER DEFAULT 0 NOT NULL");
		console(FORMAT_GRAY."[ChestShop][Debug] Inserted productMeta column");
	}

	public function __destruct()
	{
	}
}