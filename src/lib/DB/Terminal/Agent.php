<?php
namespace Ipol\DPD\DB\Terminal;

use \Ipol\DPD\API\User\UserInterface;
use \Ipol\DPD\DB\TableInterface;
use \Ipol\DPD\Utils;

/**
 * Класс реализует методы обновления информации о ПВЗ
 */
class Agent
{
	/**
	 * Конструктор
	 * 
	 * @param \Ipol\DPD\API\User\UserInterface $api   инстанс API
	 * @param \Ipol\DPD\DB\TableInterface      $table инстанс таблицы для записи данных в БД
	 */
	public function __construct(UserInterface $api, TableInterface $table)
	{
		$this->api   = $api;
		$this->table = $table;
	}

	/**
	 * @return \Ipol\DPD\User\UserInterface
	 */
	public function getApi()
	{
		return $this->api;
	}

	/**
	 * @return \Ipol\DPD\DB\Location\Table
	 */
	public function getTable()
	{
		return $this->table;
	}

	/**
	 * Загружает терминалы без ограничений по габаритам
	 * 
	 * @param string $position Стартовая позиция импорта
	 * 
	 * @return bool|string
	 */
	public function loadUnlimited($position = 0)
	{
		$position   = $position ?: 0;
		$index      = 0;
		$start_time = time();

		$items = $this->getApi()->getService('geography')->getTerminalsSelfDelivery2() ?: array();
		foreach ($items as $item) {
			if ($index++ < $position) {
				continue;
			}

			if (Utils::isNeedBreak($start_time)) {
				return $index;
			}

			$this->loadTerminal($item);
		}

		return true;
	}

	/**
	 * Загружает терминалы с ограничениями по габаритам
	 * 
	 * @param string $position Стартовая позиция импорта
	 * 
	 * @return bool|string
	 */
	public function loadLimited($position = 'RU:0')
	{
		$position   = explode(':', $position ?: 'RU:0');
		$index      = 0;
		$started    = false;
		$start_time = time();

		foreach (['RU', 'KZ', 'BY'] as $countryCode) {
			if ($position[0] != $countryCode && $started === false) {
				continue;
			}

			$started = true;
			$items   = $this->getApi()->getService('geography')->getParcelShops($countryCode) ?: array();

			foreach ($items as $item) {
				if ($index++ < $position[1]) {
					continue;
				}

				if (Utils::isNeedBreak($start_time)) {
					return sprintf('%s:%s', $countryCode, $index);
				}

				$this->loadTerminal($item);
			}
		}

		return true;
	}

	/**
	 * Сохраняет информацию о терминале в БД
	 * 
	 * @param array $item
	 * 
	 * @return bool|int
	 */
	protected function loadTerminal($item)
	{
		$fields = [
			'LOCATION_ID'               => $item['ADDRESS']['CITY_ID'],

			'CODE'                      => $item['TERMINAL_CODE'] ?: $item['CODE'],
			'NAME'                      => $this->normalizeAddress($item['ADDRESS'], true),

			'ADDRESS_FULL'              => $this->normalizeAddress($item['ADDRESS']),
			'ADDRESS_SHORT'             => $this->normalizeAddress($item['ADDRESS'], true),
			'ADDRESS_DESCR'             => $item['ADDRESS']['DESCRIPT'],

			'PARCEL_SHOP_TYPE'          => $item['PARCEL_SHOP_TYPE'],

			'SCHEDULE_SELF_PICKUP'      => implode('<br>', $this->normalizeSchedule($item['SCHEDULE'], 'SelfPickup')),
			'SCHEDULE_SELF_DELIVERY'    => implode('<br>', $this->normalizeSchedule($item['SCHEDULE'], 'SelfDelivery')),
			'SCHEDULE_PAYMENT_CASH'     => $paymentCash                                                                          = implode('<br>', $this->normalizeSchedule($item['SCHEDULE'], 'Payment')),
			'SCHEDULE_PAYMENT_CASHLESS' => $paymentCashLess                                                                      = implode('<br>', $this->normalizeSchedule($item['SCHEDULE'], 'PaymentByBankCard')),

			'LATITUDE'                  => $item['GEO_COORDINATES']['LATITUDE'],
			'LONGITUDE'                 => $item['GEO_COORDINATES']['LONGITUDE'],

			'IS_LIMITED'                => 'N',
			'LIMIT_MAX_SHIPMENT_WEIGHT' => 0,
			'LIMIT_MAX_WEIGHT'          => 0,
			'LIMIT_MAX_LENGTH'          => 0,
			'LIMIT_MAX_WIDTH'           => 0,
			'LIMIT_MAX_HEIGHT'          => 0,
			'LIMIT_MAX_VOLUME'          => 0,
			'LIMIT_SUM_DIMENSION'       => 0,

			'NPP_AMOUNT'                => $maxNppAmount                                                                         = $this->getMaxNppAmount($item),
			'NPP_AVAILABLE'             => (((bool) $maxNppAmount) && (((bool) $paymentCash) || ((bool) $paymentCashLess))) ? 'Y': 'N',
		];

		if (isset($item['LIMITS'])) {
			$fields['IS_LIMITED']                = 'Y';
			$fields['LIMIT_MAX_SHIPMENT_WEIGHT'] = $item['LIMITS']['MAX_SHIPMENT_WEIGHT'] ?: 0;
			$fields['LIMIT_MAX_WEIGHT']          = $item['LIMITS']['MAX_WEIGHT'];
			$fields['LIMIT_MAX_LENGTH']          = $item['LIMITS']['MAX_LENGTH'];
			$fields['LIMIT_MAX_WIDTH']           = $item['LIMITS']['MAX_WIDTH'];
			$fields['LIMIT_MAX_HEIGHT']          = $item['LIMITS']['MAX_HEIGHT'];
			$fields['LIMIT_MAX_VOLUME']          = round($item['LIMITS']['MAX_WIDTH'] * $item['LIMITS']['MAX_HEIGHT'] * $item['LIMITS']['MAX_LENGTH'] / 1000000, 3);
			$fields['LIMIT_SUM_DIMENSION']       = $item['LIMITS']['DIMENSION_SUM'] ?: 0;
		}
		
		$exists = $this->getTable()->getByCode($fields['CODE']);

		if ($exists) {
			$result = $this->getTable()->update($exists['ID'], $fields);
		} else {
			$result = $this->getTable()->add($fields);
		}

		return $result ? ($exists ? $exists['ID'] : $result) : false;
	}

	/**
	 * Возвращает адрес терминала в виде строки
	 * 
	 * @param array $address
	 * @param bool  $short
	 * 
	 * @return string
	 */
	protected function normalizeAddress($address, $short = false)
	{
		$ret = array();

		if ($short == false) {
			$ret[] = $address['INDEX'];

			if ($address['REGION_NAME'] != $address['CITY_NAME']) {
				$ret[] = $address['REGION_NAME'];
			}

			$ret[] = $address['CITY_NAME'];
		}

		$ret[] = $address['STREET'] .' '. $address['STREET_ABBR'];

		if (!empty($address['HOUSE_NO'])) {
			$ret[] = 'д. '. $address['HOUSE_NO'];
		}

		if (!empty($address['BUILDING'])) {
			$ret[] = 'корп. '. $address['BUILDING'];
		}

		if (!empty($address['STRUCTURE'])) {
			$ret[] = 'стр. '. $address['STRUCTURE'];
		}

		if (!empty($address['OWNERSHIP'])) {
			$ret[] = 'вл. '. $address['OWNERSHIP'];
		}

		return implode(', ', $ret);
	}

	/**
	 * Возвращает график работы терминала в виде строки
	 * 
	 * @param array  $schedule  график работы
	 * @param string $operation операция для фильтрации
	 * 
	 * @return string
	 */
	protected function normalizeSchedule($schedule, $operation)
	{
		$schedule = array_key_exists('OPERATION', $schedule)
			? array($schedule)
			: $schedule;


		$grouped = array();
		foreach($schedule as $item) {
			if ($item['OPERATION'] != $operation) {
				continue;
			}

			$timetable = array_key_exists('WEEK_DAYS', $item['TIMETABLE'])
				? array($item['TIMETABLE'])
				: $item['TIMETABLE']
			;

			foreach ($timetable as $data) {
				$grouped[$data['WORK_TIME']] = explode(',', $data['WEEK_DAYS']);
			}
		}

		$weekdays = array_flip(array(
			'Пн',
			'Вт',
			'Ср',
			'Чт',
			'Пт',
			'Сб',
			'Вс',
		));

		$ret = array();
		foreach ($grouped as $time => $days) {
			usort($days, function($a, $b) use ($weekdays) {
				return $weekdays[$a] - $weekdays[$b];
			});

			$fromDay   = reset($days);
			$fromIndex = $weekdays[$fromDay];
			$prevDay   = $fromDay;
			$prevIndex = $fromIndex;

			$timetable = '';
			foreach($days as $day) {
				$currentIndex = $weekdays[$day];
				if ($currentIndex - $prevIndex > 1) {
					$timetable .= $fromDay . ($fromDay != $prevDay ? '-'. $prevDay : '') .',';
					$fromDay = $day;
				}
				$prevDay = $day;
				$prevIndex = $currentIndex;
			}

			$ret[] = $timetable 
				. $fromDay 
				. ($fromDay != $prevDay ? '-'. $prevDay : '')
				. ': '. $time;
		}

		return $ret;
	}

	/**
	 * Возвращает макс. сумму наложенного платежа
	 * 
	 * @param array $item
	 * 
	 * @return double
	 */
	protected function getMaxNppAmount($item)
	{
		if (isset($item['EXTRA_SERVICE'])) {
			$extraServices = array_key_exists('ES_CODE', $item['EXTRA_SERVICE']) ? array($item['EXTRA_SERVICE']) : $item['EXTRA_SERVICE'];

			foreach ($extraServices as $extraService) {
				if ($extraService['ES_CODE'] == 'НПП') {
					return $extraService['PARAMS']['VALUE'] ?: 9999999999;
				}
			}
		}

		return 0;
	}
}