<?php

namespace App\Sync\Task;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity;
use App\Flysystem\FilesystemManager;
use DoctrineBatchUtils\BatchProcessing\SimpleBatchIteratorAggregate;
use Psr\Log\LoggerInterface;

class CheckFolderPlaylistsTask extends AbstractTask
{
    protected Entity\Repository\StationPlaylistFolderRepository $folderRepo;

    protected Entity\Repository\StationPlaylistMediaRepository $spmRepo;

    protected FilesystemManager $filesystem;

    public function __construct(
        ReloadableEntityManagerInterface $em,
        LoggerInterface $logger,
        Entity\Repository\StationPlaylistMediaRepository $spmRepo,
        Entity\Repository\StationPlaylistFolderRepository $folderRepo,
        FilesystemManager $filesystem
    ) {
        parent::__construct($em, $logger);

        $this->spmRepo = $spmRepo;
        $this->folderRepo = $folderRepo;
        $this->filesystem = $filesystem;
    }

    public function run(bool $force = false): void
    {
        $stations = SimpleBatchIteratorAggregate::fromQuery(
            $this->em->createQuery(
                <<<'DQL'
                    SELECT s FROM App\Entity\Station s
                DQL
            ),
            1
        );

        foreach ($stations as $station) {
            /** @var Entity\Station $station */

            $this->logger->info(
                'Processing auto-assigning folders for station...',
                [
                    'station' => $station->getName(),
                ]
            );

            $this->syncPlaylistFolders($station);
            gc_collect_cycles();
        }
    }

    public function syncPlaylistFolders(Entity\Station $station): void
    {
        $folderPlaylists = $this->em->createQuery(
            <<<'DQL'
                SELECT spf, sp
                FROM App\Entity\StationPlaylistFolder spf
                JOIN spf.playlist sp
                WHERE spf.station = :station
            DQL
        )->setParameter('station', $station)
            ->execute();

        $folders = [];

        $fs = $this->filesystem->getForStation($station);

        foreach ($folderPlaylists as $row) {
            /** @var Entity\StationPlaylistFolder $row */
            $path = $row->getPath();

            if ($fs->has(FilesystemManager::PREFIX_MEDIA . '://' . $path)) {
                $folders[$path][] = $row->getPlaylist();
            } else {
                $this->em->remove($row);
            }
        }

        $this->em->flush();

        $mediaInFolderQuery = $this->em->createQuery(
            <<<'DQL'
                SELECT sm
                FROM App\Entity\StationMedia sm
                WHERE sm.storage_location = :storageLocation
                AND sm.path LIKE :path
            DQL
        )->setParameter('storageLocation', $station->getMediaStorageLocation());

        foreach ($folders as $path => $playlists) {
            $mediaInFolder = $mediaInFolderQuery->setParameter('path', $path . '/%')
                ->execute();

            foreach ($mediaInFolder as $media) {
                foreach ($playlists as $playlist) {
                    /** @var Entity\StationMedia $media */
                    /** @var Entity\StationPlaylist $playlist */

                    if (
                        Entity\StationPlaylist::ORDER_SEQUENTIAL !== $playlist->getOrder()
                        && Entity\StationPlaylist::SOURCE_SONGS === $playlist->getSource()
                    ) {
                        $this->spmRepo->addMediaToPlaylist($media, $playlist);
                    }
                }
            }
        }

        $this->em->flush();
        $this->em->clear();
    }
}
