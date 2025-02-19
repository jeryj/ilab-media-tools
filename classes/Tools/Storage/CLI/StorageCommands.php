<?php
// Copyright (c) 2016 Interfacelab LLC. All rights reserved.
//
// Released under the GPLv3 license
// http://www.gnu.org/licenses/gpl-3.0.html
//
// Uses code from:
// Persist Admin Notices Dismissal
// by Agbonghama Collins and Andy Fragen
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// **********************************************************************

namespace ILAB\MediaCloud\Tools\Storage\CLI;

use ILAB\MediaCloud\CLI\Command;
use ILAB\MediaCloud\Storage\StorageSettings;
use ILAB\MediaCloud\Tasks\BatchManager;
use ILAB\MediaCloud\Tools\Storage\DefaultProgressDelegate;
use ILAB\MediaCloud\Tools\Storage\StorageTool;
use ILAB\MediaCloud\Tools\ToolsManager;
use ILAB\MediaCloud\Utilities\Logging\Logger;

if (!defined('ABSPATH')) { header('Location: /'); die; }

/**
 * Import to Cloud Storage, rebuild thumbnails, etc.
 * @package ILAB\MediaCloud\CLI\Storage
 */
class StorageCommands extends Command {
    private $debugMode = false;

	/**
	 * Imports the media library to the cloud.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : The maximum number of items to process, default is infinity.
	 *
	 * [--offset=<number>]
	 * : The starting offset to process.  Cannot be used with page.
	 *
	 * [--page=<number>]
	 * : The starting offset to process.  Page numbers start at 1.  Cannot be used with offset.
	 *
	 * @when after_wp_load
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function import($args, $assoc_args) {
	    $this->debugMode = (\WP_CLI::get_config('debug') == 'mediacloud');

	    // Force the logger to initialize
	    Logger::instance();

		/** @var StorageTool $storageTool */
		$storageTool = ToolsManager::instance()->tools['storage'];

		if (!$storageTool || !$storageTool->enabled()) {
			Command::Error('Storage tool is not enabled in Media Cloud or the settings are incorrect.');
			return;
		}


		$postArgs = [
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'fields' => 'ids',
		];

		if (isset($assoc_args['limit'])) {
			$postArgs['posts_per_page'] = $assoc_args['limit'];
			if (isset($assoc_args['offset'])) {
				$postArgs['offset'] = $assoc_args['offset'];
			} else if (isset($assoc_args['page'])) {
				$postArgs['offset'] = max(0,($assoc_args['page'] - 1) * $assoc_args['limit']);
			}
		} else {
			$postArgs['nopaging'] = true;
		}


		if(!StorageSettings::uploadDocuments()) {
			$args['post_mime_type'] = 'image';
			$totalAttachmentsData = wp_count_attachments('image');
		} else {
			$totalAttachmentsData = wp_count_attachments('image');
		}

		$totalAttachments = 0;
		$totalAttachmentsData = json_decode(json_encode($totalAttachmentsData), true);
		foreach($totalAttachmentsData as $key => $count) {
			$totalAttachments += $count;
		}

		$query = new \WP_Query($postArgs);

		if($query->post_count > 0) {
		    BatchManager::instance()->reset('storage');

            BatchManager::instance()->setStatus('storage', true);
            BatchManager::instance()->setTotalCount('storage', $query->post_count);
            BatchManager::instance()->setCurrent('storage', 1);
            BatchManager::instance()->setShouldCancel('storage', false);

			Command::Info("Total posts to be processsed: %Y{$query->post_count}%N of %Y{$totalAttachments}%N.", true);

			$pd = new DefaultProgressDelegate();

			for($i = 1; $i <= $query->post_count; $i++) {
				$postId = $query->posts[$i - 1];
				$upload_file = get_attached_file($postId);
				$fileName = basename($upload_file);

				if (!is_file($upload_file)) {
					Command::Info("%w[%C{$i}%w of %C{$query->post_count}%w] %Skipping file - file not found - %Y$upload_file%N %w(Post ID %N$postId%w)%N ... ", $this->debugMode);
					continue;
				}

                BatchManager::instance()->setCurrentFile('storage', $fileName);
                BatchManager::instance()->setCurrent('storage', $i);

				Command::Info("%w[%C{$i}%w of %C{$query->post_count}%w] %NImporting %Y$fileName%N %w(Post ID %N$postId%w)%N ... ", $this->debugMode);
				$storageTool->processImport($i - 1, $postId, $pd);
				if (!$this->debugMode) {
                    Command::Info("%YDone%N.", true);
                }
			}

			BatchManager::instance()->reset('storage');
		}
	}

	/**
	 * Regenerate thumbnails
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : The maximum number of items to process, default is infinity.
	 *
	 * [--offset=<number>]
	 * : The starting offset to process.  Cannot be used with page.
	 *
	 * [--page=<number>]
	 * : The starting offset to process.  Page numbers start at 1.  Cannot be used with offset.
	 *
	 * @when after_wp_load
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function regenerate($args, $assoc_args) {
		/** @var StorageTool $storageTool */
		$storageTool = ToolsManager::instance()->tools['storage'];

		if (!$storageTool || !$storageTool->enabled()) {
			Command::Error('Storage tool is not enabled in Media Cloud or the settings are incorrect.');
			return;
		}

		$postArgs = [
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'post_mime_type' => 'image',
			'fields' => 'ids',
		];

		if (isset($assoc_args['limit'])) {
			$postArgs['posts_per_page'] = $assoc_args['limit'];
			if (isset($assoc_args['offset'])) {
				$postArgs['offset'] = $assoc_args['offset'];
			} else if (isset($assoc_args['page'])) {
				$postArgs['offset'] = max(0,($assoc_args['page'] - 1) * $assoc_args['limit']);
			}
		} else {
			$postArgs['nopaging'] = true;
		}

		$query = new \WP_Query($postArgs);

		if($query->post_count > 0) {
            BatchManager::instance()->reset('thumbnails');

            BatchManager::instance()->setStatus('thumbnails', true);
            BatchManager::instance()->setTotalCount('thumbnails', $query->post_count);
            BatchManager::instance()->setCurrent('thumbnails', 1);
            BatchManager::instance()->setShouldCancel('thumbnails', false);

			Command::Info("Total posts found: %Y{$query->post_count}.", true);

			$pd = new DefaultProgressDelegate();

			for($i = 1; $i <= $query->post_count; $i++) {
				$postId = $query->posts[$i - 1];
				$upload_file = get_attached_file($postId);
				$fileName = basename($upload_file);

                BatchManager::instance()->setCurrentFile('thumbnails', $fileName);
                BatchManager::instance()->setCurrent('thumbnails', $i);

				Command::Info("%w[%C{$i}%w of %C{$query->post_count}%w] %NRegenerating thumbnails for %Y$fileName%N %w(%N$postId%w)%N ... ");
				$storageTool->regenerateFile($postId);
				Command::Info("%YDone%N.", true);
			}

            BatchManager::instance()->reset('thumbnails');
		}

	}


	/**
	 * Unlinks media from the cloud.  Important: This will not attempt to download any media from the cloud before it unlinks it.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : The maximum number of items to process, default is infinity.
	 *
	 * [--offset=<number>]
	 * : The starting offset to process.  Cannot be used with page.
	 *
	 * [--page=<number>]
	 * : The starting offset to process.  Page numbers start at 1.  Cannot be used with offset.
	 *
	 * @when after_wp_load
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function unlink($args, $assoc_args) {
		if (ToolsManager::instance()->toolEnabled('storage')) {
			Command::Error("%WCloud Storage tool needs to be disabled before you can run this command.%n");
			exit(1);
		}

		$postArgs = [
			'post_type' => 'attachment',
			'post_status' => 'inherit'
		];

		if (isset($assoc_args['limit'])) {
			$postArgs['posts_per_page'] = $assoc_args['limit'];
			if (isset($assoc_args['offset'])) {
				$postArgs['offset'] = $assoc_args['offset'];
			} else if (isset($assoc_args['page'])) {
				$postArgs['offset'] = max(0,($assoc_args['page'] - 1) * $assoc_args['limit']);
			}
		} else {
			$postArgs['nopaging'] = true;
		}

		$q = new \WP_Query($postArgs);

		Command::Out("", true);
		Command::Warn("%WThis command only unlinks media attachments from cloud storage, \nit will not download any media from cloud storage. If the attachments \nyou are unlinking do not exist on your server, you will have broken \nimages on your site.%n");
		Command::Out("", true);

		\WP_CLI::confirm("Are you sure you want to continue?", $assoc_args);

		Command::Out("", true);
		Command::Info("Found %W{$q->post_count}%n attachments.", true);
		Command::Info("Processing ...");


		foreach($q->posts as $post) {
			$meta = wp_get_attachment_metadata($post->ID);
			if (isset($meta['s3'])) {
				unset($meta['s3']);
				if (isset($meta['sizes'])) {
					$sizes = $meta['sizes'];
					foreach($sizes as $size => $sizeData) {
						if (isset($sizeData['s3'])) {
							unset($sizeData['s3']);
						}

						$sizes[$size] = $sizeData;
					}

					$meta['sizes'] = $sizes;
				}
				wp_update_attachment_metadata($post->ID, $meta);
			}
			Command::Info('.');
		}

		Command::Info(' %GDone.%n', true);
		Command::Out("", true);
	}

	public static function Register() {
		\WP_CLI::add_command('mediacloud', __CLASS__);
	}

}