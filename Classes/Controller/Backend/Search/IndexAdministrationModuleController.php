<?php
namespace ApacheSolrForTypo3\Solr\Controller\Backend\Search;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013-2015 Ingo Renner <ingo@typo3.org>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\IndexQueue\Queue;
use ApacheSolrForTypo3\Solr\SolrService;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Web\ReferringRequest;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Index Administration Module
 *
 * @author Ingo Renner <ingo@typo3.org>
 */
class IndexAdministrationModuleController extends AbstractModuleController
{

    /**
     * @var Queue
     */
    protected $indexQueue;

    /**
     * @var ConnectionManager
     */
    protected $solrConnectionManager = null;

    /**
     * Initializes the controller before invoking an action method.
     */
    protected function initializeAction()
    {
        parent::initializeAction();
        $this->indexQueue = GeneralUtility::makeInstance(Queue::class);
        $this->solrConnectionManager = GeneralUtility::makeInstance(ConnectionManager::class);
    }

    /**
     * Index action, shows an overview of available index maintenance operations.
     *
     * @return void
     */
    public function indexAction()
    {
        if ($this->selectedSite === null || empty($this->solrConnectionManager->getConnectionsBySite($this->selectedSite))) {
            $this->view->assign('can_not_proceed', true);
        }
    }

    /**
     * Empties the site's indexes.
     *
     * @return void
     */
    public function emptyIndexAction()
    {
        $siteHash = $this->selectedSite->getSiteHash();

        try {
            $affectedCores = [];
            $solrServers = $this->solrConnectionManager->getConnectionsBySite($this->selectedSite);
            foreach ($solrServers as $solrServer) {
                /* @var $solrServer SolrService */
                $solrServer->deleteByQuery('siteHash:' . $siteHash);
                $solrServer->commit(false, false, false);
                $affectedCores[] = $solrServer->getCoreName();
            }
            $this->addFlashMessage(LocalizationUtility::translate('solr.backend.index_administration.index_emptied_all', 'Solr', [$this->selectedSite->getLabel(), implode(', ', $affectedCores)]));
        } catch (\Exception $e) {
            $this->addFlashMessage(LocalizationUtility::translate('solr.backend.index_administration.error.on_empty_index', 'Solr', [$e->__toString()]), '', FlashMessage::ERROR);
        }

        $this->redirect('index');
    }

    /**
     * Empties the Index Queue
     *
     * @return void
     */
    public function clearIndexQueueAction()
    {
        $this->indexQueue->deleteItemsBySite($this->selectedSite);
        $this->addFlashMessage(
            LocalizationUtility::translate('solr.backend.index_administration.success.queue_emptied', 'Solr',
                [$this->selectedSite->getLabel()])
        );
        $this->redirectToReferrerModule();
    }

    /**
     * Reloads the site's Solr cores.
     *
     * @return void
     */
    public function reloadIndexConfigurationAction()
    {
        $coresReloaded = true;
        $reloadedCores = [];
        $solrServers = $this->solrConnectionManager->getConnectionsBySite($this->selectedSite);

        foreach ($solrServers as $solrServer) {
            /* @var $solrServer SolrService */
            $coreReloaded = $solrServer->reloadCore()->getHttpStatus() === 200;
            $coreName = $solrServer->getCoreName();

            if (!$coreReloaded) {
                $coresReloaded = false;

                $this->addFlashMessage(
                    'Failed to reload index configuration for core "' . $coreName . '"',
                    '',
                    FlashMessage::ERROR
                );
                break;
            }

            $reloadedCores[] = $coreName;
        }

        if ($coresReloaded) {
            $this->addFlashMessage(
                'Core configuration reloaded (' . implode(', ', $reloadedCores) . ').',
                '',
                FlashMessage::OK
            );
        }

        $this->redirect('index');
    }

    /**
     * Redirects to the referrer module index Action.
     *
     * Fluids <f:form VH can not make urls to other modules properly.
     * The module name/key is not provided in the hidden fields __referrer by bulding form.
     * So this is currently the single way to make it possible.
     *
     * @todo: remove this method if f:form works properly between backend modules.
     */
    protected function redirectToReferrerModule()
    {
        /* @var ReferringRequest $referringRequest */
        $referringRequest = $this->request->getReferringRequest();
        $controllerName = $this->request->getControllerName();
        $referrerControllerName = $referringRequest->getControllerName();
        if ($controllerName === $referrerControllerName) {
            $this->redirect('index');
            return;
        }
        /* @var BackendUriBuilder $backendUriBuilder */
        $backendUriBuilder = GeneralUtility::makeInstance(BackendUriBuilder::class);
        $referrerUriFromBackendUriBuilder = $backendUriBuilder->buildUriFromModule(
            'searchbackend_SolrIndexqueue',
            [
                'id' => $this->selectedPageUID
            ]
        );
        $this->redirectToUri($referrerUriFromBackendUriBuilder);
    }
}
