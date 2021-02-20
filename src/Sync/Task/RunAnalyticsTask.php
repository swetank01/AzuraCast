<?php

namespace App\Sync\Task;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity;
use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;

class RunAnalyticsTask extends AbstractTask
{
    protected Entity\Repository\SettingsRepository $settingsRepo;

    protected Entity\Repository\AnalyticsRepository $analyticsRepo;

    protected Entity\Repository\ListenerRepository $listenerRepo;

    protected Entity\Repository\SongHistoryRepository $historyRepo;

    public function __construct(
        ReloadableEntityManagerInterface $em,
        LoggerInterface $logger,
        Entity\Repository\SettingsRepository $settingsRepo,
        Entity\Repository\AnalyticsRepository $analyticsRepo,
        Entity\Repository\ListenerRepository $listenerRepo,
        Entity\Repository\SongHistoryRepository $historyRepo
    ) {
        parent::__construct($em, $logger);

        $this->settingsRepo = $settingsRepo;
        $this->analyticsRepo = $analyticsRepo;
        $this->listenerRepo = $listenerRepo;
        $this->historyRepo = $historyRepo;
    }

    public function run(bool $force = false): void
    {
        $settings = $this->settingsRepo->readSettings();
        $analytics_level = $settings->getAnalytics();

        switch ($analytics_level) {
            case Entity\Analytics::LEVEL_NONE:
                $this->purgeListeners();
                $this->purgeAnalytics();
                break;

            case Entity\Analytics::LEVEL_NO_IP:
                $this->purgeListeners();
                $this->updateAnalytics(false);
                break;

            case Entity\Analytics::LEVEL_ALL:
                $this->updateAnalytics(true);
                break;
        }
    }

    protected function updateAnalytics(bool $withListeners = true): void
    {
        $stationsRaw = $this->em->getRepository(Entity\Station::class)
            ->findAll();

        /** @var Entity\Station[] $stations */
        $stations = [];
        foreach ($stationsRaw as $station) {
            /** @var Entity\Station $station */
            $stations[$station->getId()] = $station;
        }

        $now = CarbonImmutable::now('UTC');
        $day = $now->subDays(5)->startOfDay();// Clear existing analytics in this segment

        $this->analyticsRepo->cleanup();
        $this->analyticsRepo->clearAllAfterTime($day);

        while ($day < $now) {
            $dailyUniqueListeners = null;

            for ($hour = 0; $hour <= 23; $hour++) {
                $hourUtc = $day->setTime($hour, 0);

                $hourlyMin = null;
                $hourlyMax = null;
                $hourlyAverage = 0;
                $hourlyUniqueListeners = null;

                foreach ($stations as $stationId => $station) {
                    $stationTz = $station->getTimezoneObject();

                    $start = $hourUtc->shiftTimezone($stationTz);
                    $end = $start->addHour();

                    [$min, $max, $avg] = $this->historyRepo->getStatsByTimeRange(
                        $station,
                        $start->getTimestamp(),
                        $end->getTimestamp()
                    );

                    $unique = null;
                    if ($withListeners) {
                        $unique = $this->listenerRepo->getUniqueListeners($station, $start, $end);

                        $hourlyUniqueListeners ??= 0;
                        $hourlyUniqueListeners += $unique;
                    }

                    $hourlyRow = new Entity\Analytics(
                        $hourUtc,
                        $station,
                        Entity\Analytics::INTERVAL_HOURLY,
                        $min,
                        $max,
                        $avg,
                        $unique
                    );

                    $this->em->persist($hourlyRow);

                    if (null === $hourlyMin) {
                        $hourlyMin = $min;
                    } else {
                        $hourlyMin = min($hourlyMin, $min);
                    }

                    if (null === $hourlyMax) {
                        $hourlyMax = $max;
                    } else {
                        $hourlyMax = max($hourlyMax, $max);
                    }

                    $hourlyAverage += $avg;
                }

                // Post the all-stations hourly totals.
                $hourlyAllStationsRow = new Entity\Analytics(
                    $hourUtc,
                    null,
                    Entity\Analytics::INTERVAL_HOURLY,
                    $hourlyMin ?? 0,
                    $hourlyMax ?? 0,
                    $hourlyAverage,
                    $hourlyUniqueListeners
                );

                $this->em->persist($hourlyAllStationsRow);
            }

            // Aggregate daily totals.
            $dailyMin = null;
            $dailyMax = null;
            $dailyAverage = 0;
            $dailyUniqueListeners = null;

            foreach ($stations as $stationId => $station) {
                $stationTz = $station->getTimezoneObject();
                $stationDayStart = $day->shiftTimezone($stationTz);
                $stationDayEnd = $stationDayStart->addDay();

                [$dailyStationMin, $dailyStationMax, $dailyStationAverage] = $this->historyRepo->getStatsByTimeRange(
                    $station,
                    $stationDayStart->getTimestamp(),
                    $stationDayEnd->getTimestamp()
                );

                if (null === $dailyMin) {
                    $dailyMin = $dailyStationMin;
                } else {
                    $dailyMin = min($dailyMin, $dailyStationMin);
                }

                if (null === $dailyMax) {
                    $dailyMax = $dailyStationMax;
                } else {
                    $dailyMax = max($dailyMax, $dailyStationMax);
                }

                $dailyAverage += $dailyStationAverage;

                $dailyStationUnique = null;
                if ($withListeners) {
                    $dailyStationUnique = $this->listenerRepo->getUniqueListeners(
                        $station,
                        $stationDayStart,
                        $stationDayEnd
                    );

                    $dailyUniqueListeners ??= 0;
                    $dailyUniqueListeners += $dailyStationUnique;
                }

                $dailyStationRow = new Entity\Analytics(
                    $day,
                    $station,
                    Entity\Analytics::INTERVAL_DAILY,
                    $dailyStationMin,
                    $dailyStationMax,
                    $dailyStationAverage,
                    $dailyStationUnique
                );

                $this->em->persist($dailyStationRow);
            }

            // Post the all-stations daily total.
            $dailyAllStationsRow = new Entity\Analytics(
                $day,
                null,
                Entity\Analytics::INTERVAL_DAILY,
                $dailyMin ?? 0,
                $dailyMax ?? 0,
                $dailyAverage,
                $dailyUniqueListeners
            );
            $this->em->persist($dailyAllStationsRow);

            $this->em->flush();

            // Loop to the next day.
            $day = $day->addDay();
        }
    }

    protected function purgeAnalytics(): void
    {
        $this->analyticsRepo->clearAll();
    }

    protected function purgeListeners(): void
    {
        $this->listenerRepo->clearAll();
    }
}
