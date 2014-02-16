<?php

/*
 __PocketMine Plugin__
name=ChestShop
description=You can open your chest shop and purchase from others' chest shop.
version=1.7.3
author=MinecrafterJPN
class=ChestShop
apiversion=11
*/

class ChestShop implements Plugin
{
	private $api, $db, $config;

	const CHEST_SLOTS = 27;
	const CONFIG_POCKETMONEY = 0;
	const CONFIG_ECONOMY = 1;

	public function __construct(ServerAPI $api, $server = false)
	{
		$this->api = $api;
	}

	public function init()
	{
		foreach ($this->api->getList() as $i => $ob) {
			$tmp[] = get_class($ob);
		}
		if (file_exists("./plugins/PocketMoney.php")) {
			$this->config["moneyplugin"] = self::CONFIG_POCKETMONEY;
		} elseif (in_array("EconomyAPI", $tmp)) {
			$this->config["moneyplugin"] = self::CONFIG_ECONOMY;
		} else {
			console(FORMAT_RED . "[ChestShop][Error] PocketMoney or €¢onom¥$ has not been loaded.");
			$this->api->console->defaultCommands("stop", array(), false, false);
		}
		$this->loadDB();
		$this->api->addHandler("tile.update", array($this, "eventHandler"));
		$this->api->addHandler("player.block.touch", array($this, "eventHandler"));
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
				$tile = $this->api->tile->get(new Position($data['target']->x, $data['target']->y, $data['target']->z, $data['player']->level));
				if($tile === false) break;
				$class = $tile->class;
				switch ($class) {
					case TILE_SIGN:
						switch ($data['type']) {
							case "place":
								$shopInfo = $this->db->query("SELECT * FROM ChestShop WHERE signX = {$data['target']->x} AND signY = {$data['target']->y} AND signZ = {$data['target']->z}")->fetchArray(SQLITE3_ASSOC);
								if($shopInfo === false) break;
								if ($shopInfo['shopOwner'] === $data['player']->username) {
									$this->api->chat->sendTo(false, "[ChestShop]Cannot purchase from your own shop.", $data['player']->username);
									break;
								}
								foreach($this->api->plugin->getList() as $plugin) {
									$tmp[] = $plugin['name'];
								}
								$buyerMoney = $this->api->dhandle("money.player.get", array('username' => $data['player']->username));
								if ($buyerMoney === false) break;
								if ($buyerMoney < $shopInfo['price']) {
									$this->api->chat->sendTo(false, "[ChestShop]Your money is not enough.", $data['player']->username);
									break;
								}
								$chest = $this->api->tile->get(new Position($shopInfo['chestX'], $shopInfo['chestY'], $shopInfo['chestZ'], $data['player']->level));
								$saleNum = 0;
								for ($i = 0; $i < self::CHEST_SLOTS; $i++) {
									$item = $chest->getSlot($i);
									if ($item->getID() === $shopInfo['productID']) {
										$saleNum += $item->count;
									}
								}
								if ($saleNum < $shopInfo['saleNum']) {
									$this->api->chat->sendTo(false, "[ChestShop] This shop is out of stack!", $data['player']->username);
									$this->api->chat->sendTo(false, "[ChestShop] Notify the owner", $data['player']->username);
									$this->api->chat->sendTo(false, "[ChestShop] Your ChestShop is out of stack! Replenish stock!", $shopInfo['shopOwner']);
									break;
								}
								$this->api->block->commandHandler("give", array($data['player']->username, $shopInfo['productID'], $shopInfo['saleNum']), $data['player'], false);
								$tmpNum = $shopInfo['saleNum'];
								for ($i = 0; $i < self::CHEST_SLOTS; $i++) {
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
								if ($this->config["moneyplugin"] === self::CONFIG_POCKETMONEY) {
									$this->api->dhandle("money.handle", array(
											'username' => $data['player']->username,
											'method' => 'grant',
											'amount' => -$shopInfo['price']
									));
									$this->api->dhandle("money.handle", array(
											'username' => $shopInfo['shopOwner'],
											'method' => 'grant',
											'amount' => $shopInfo['price']
									));
								} elseif($this->config["moneyplugin"] === self::CONFIG_ECONOMY) {
									$this->api->economy->useMoney($data['player']->username, $shopInfo['price']);
									$this->api->economy->takeMoney($shopInfo['shopOwner'], $shopInfo['price']);
								}
								$this->api->chat->sendTo(false, "[ChestShop] Completed the transaction.", $data['player']->username);
								$this->api->chat->sendTo(false, "[ChestShop] {$data['player']->username} purchased your product: {$shopInfo['price']}PM", $shopInfo['shopOwner']);
								break;
							case "break":
								$result = $this->db->query("SELECT * FROM ChestShop WHERE signX = {$data['target']->x} AND signY = {$data['target']->y} AND signZ = {$data['target']->z}")->fetchArray(SQLITE3_ASSOC);
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
						$result = $this->db->query("SELECT * FROM ChestShop WHERE chestX = {$data['target']->x} AND chestY = {$data['target']->y} AND chestZ = {$data['target']->z}")->fetchArray(SQLITE3_ASSOC);
						if ($result === false) break;
						if ($result['shopOwner'] !== $data['player']->username) {
							$this->api->chat->sendTo(false, "[ChestShop]This chest is protected.", $data['player']->username);
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
		$tmp = strtolower($item);
		if (isset($this->blocks[$tmp])) return $item;
		if (isset($this->items[$tmp])) return $item;
		if (($id = array_search($tmp, $this->blocks)) !== false) return $id;
		if (($id = array_search($tmp, $this->items)) !== false) return $id;
		return false;
	}

	public function __destruct()
	{
	}

	private $blocks = array(
			0 => "air",
			1 => "stone",
			2 => "grass",
			3 => "dirt",
			4 => "cobblestone",
			5 => "woodenplank",
			6 => "sapling",
			7 => "bedrock",
			8 => "water",
			9 => "stationarywater",
			10 => "lava",
			11 => "stationarylava",
			12 => "sand",
			13 => "gravel",
			14 => "goldore",
			15 => "ironore",
			16 => "coalore",
			17 => "wood",
			18 => "leaves",
			19 => "sponge",
			20 => "glass",
			21 => "lapislazuliore",
			22 => "lapislazuliblock",
			23 => "dispenser",
			24 => "sandstone",
			25 => "noteblock",
			26 => "bed",
			27 => "poweredrail",
			28 => "detectorrail",
			29 => "stickypiston",
			30 => "cobweb",
			31 => "tallgrass",
			32 => "deadshrub",
			35 => "wool",
			37 => "yellowflower",
			38 => "cyanflower",
			39 => "brownmushroom",
			40 => "redmushroom",
			41 => "blockofgold",
			42 => "blockofiron",
			44 => "stoneslab",
			45 => "brick",
			46 => "tnt",
			47 => "bookcase",
			48 => "mossstone",
			49 => "obsidian",
			50 => "torch",
			51 => "fire",
			52 => "mobspawner",
			53 => "woodenstairs",
			56 => "diamondore",
			57 => "blockofdiamond",
			58 => "workbench",
			59 => "wheat",
			60 => "farmland",
			61 => "furnace",
			62 => "furnace",
			64 => "wooddoor",
			65 => "ladder",
			66 => "rails",
			67 => "cobblestonestairs",
			71 => "irondoor",
			73 => "redstoneore",
			78 => "snow",
			79 => "ice",
			80 => "snowblock",
			81 => "cactus",
			82 => "clayblock",
			83 => "sugarcane",
			85 => "fence",
			87 => "netherrack",
			88 => "soulsand",
			89 => "glowstone",
			96 => "trapdoor",
			98 => "stonebricks",
			99 => "brownmushroom",
			100 => "redmushroom",
			102 => "glasspane",
			103 => "melon",
			105 => "melonvine",
			107 => "fencegate",
			108 => "brickstairs",
			109 => "stonebrickstairs",
			112 => "netherbrick",
			114 => "netherbrickstairs",
			128 => "sandstonestairs",
			155 => "blockofquartz",
			156 => "quartzstairs",
			245 => "stonecutter",
			246 => "glowingobsidian",
			247 => "netherreactorcore"
	);
	private $items = array(
			256 => "ironshovel",
			257 => "ironpickaxe",
			258 => "ironaxe",
			259 => "flintandsteel",
			260 => "apple",
			261 => "bow",
			262 => "arrow",
			263 => "coal",
			264 => "diamondgem",
			265 => "ironingot",
			266 => "goldingot",
			267 => "ironsword",
			268 => "woodensword",
			269 => "woodenshovel",
			270 => "woodenpickaxe",
			271 => "woodenaxe",
			272 => "stonesword",
			273 => "stoneshovel",
			274 => "stonepickaxe",
			275 => "stoneaxe",
			276 => "diamondsword",
			277 => "diamondshovel",
			278 => "diamondpickaxe",
			279 => "diamondaxe",
			280 => "stick",
			281 => "bowl",
			282 => "mushroomstew",
			283 => "goldsword",
			284 => "goldshovel",
			285 => "goldpickaxe",
			286 => "goldaxe",
			287 => "string",
			288 => "feather",
			289 => "gunpowder",
			290 => "woodenhoe",
			291 => "stonehoe",
			292 => "ironhoe",
			293 => "diamondhoe",
			294 => "goldhoe",
			295 => "wheatseeds",
			296 => "wheat",
			297 => "bread",
			298 => "leatherhelmet",
			299 => "leatherchestplate",
			300 => "leatherleggings",
			301 => "leatherboots",
			302 => "chainmailhelmet",
			303 => "chainmailchestplate",
			304 => "chainmailleggings",
			305 => "chainmailboots",
			306 => "ironhelmet",
			307 => "ironchestplate",
			308 => "ironleggings",
			309 => "ironboots",
			310 => "diamondhelmet",
			311 => "diamondchestplate",
			312 => "diamondleggings",
			313 => "diamondboots",
			314 => "goldhelmet",
			315 => "goldchestplate",
			316 => "goldleggings",
			317 => "goldboots",
			318 => "flint",
			319 => "rawporkchop",
			320 => "cookedporkchop",
			321 => "painting",
			322 => "goldapple",
			323 => "sign",
			332 => "snowball",
			334 => "leather",
			336 => "claybrick",
			337 => "clay",
			338 => "sugarcane",
			339 => "paper",
			340 => "book",
			344 => "egg",
			348 => "glowstonedust",
			352 => "bone",
			353 => "sugar",
			355 => "bed",
			359 => "shears",
			360 => "melon",
			362 => "melonseeds",
			363 => "rawbeef",
			364 => "steak",
			365 => "rawchicken",
			366 => "cookedchicken",
			405 => "netherbrick",
			456 => "camera"
	);
}