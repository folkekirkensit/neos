<?php
namespace TYPO3\Neos\Controller\Module\Management;

/*
 * This file is part of the TYPO3.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Eel\FlowQuery\FlowQuery;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Error\Message;
use TYPO3\Neos\Service\UserService;
use TYPO3\TYPO3CR\Domain\Model\Workspace;

/**
 * The TYPO3 Workspaces module controller
 *
 * @Flow\Scope("singleton")
 */
class WorkspacesController extends \TYPO3\Neos\Controller\Module\AbstractModuleController
{
    /**
     * @Flow\Inject
     * @var \TYPO3\Neos\Service\PublishingService
     */
    protected $publishingService;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var \TYPO3\Neos\Domain\Repository\SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Property\PropertyMapper
     */
    protected $propertyMapper;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Security\Context
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var \TYPO3\Neos\Domain\Service\ContentContextFactory
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Property\PropertyMappingConfigurationBuilder
     */
    protected $propertyMappingConfigurationBuilder;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @return void
     */
    protected function initializeAction()
    {
        if ($this->arguments->hasArgument('node')) {
            $this->arguments->getArgument('node')->getPropertyMappingConfiguration()->setTypeConverterOption('TYPO3\TYPO3CR\TypeConverter\NodeConverter', \TYPO3\TYPO3CR\TypeConverter\NodeConverter::REMOVED_CONTENT_SHOWN, true);
        }
        if ($this->arguments->hasArgument('nodes')) {
            $this->arguments->getArgument('nodes')->getPropertyMappingConfiguration()->forProperty('*')->setTypeConverterOption('TYPO3\TYPO3CR\TypeConverter\NodeConverter', \TYPO3\TYPO3CR\TypeConverter\NodeConverter::REMOVED_CONTENT_SHOWN, true);
        }
        parent::initializeAction();
    }

    /**
     * @param Workspace $workspace
     * @return void
     * @todo Pagination
     * @todo Tree filtering + level limit
     * @todo Search field
     * @todo Difference mechanism
     */
    public function indexAction(Workspace $workspace = null)
    {
        if ($workspace === null) {
            $workspace = $this->userService->getCurrentWorkspace();
        }

        $sites = array();
        foreach ($this->publishingService->getUnpublishedNodes($workspace) as $node) {
            $pathParts = explode('/', $node->getPath());
            if (count($pathParts) > 2) {
                $siteNodeName = $pathParts[2];
                $q = new FlowQuery(array($node));
                $document = $q->closest('[instanceof TYPO3.Neos:Document]')->get(0);
                // FIXME: $document will be NULL if we have a broken rootline for this node. This actually should never happen, but currently can in some scenarios.
                if ($document !== null) {
                    $documentPath = implode('/', array_slice(explode('/', $document->getPath()), 3));
                    $relativePath = str_replace(sprintf('/sites/%s/%s', $siteNodeName, $documentPath), '', $node->getPath());
                    if (!isset($sites[$siteNodeName]['siteNode'])) {
                        $sites[$siteNodeName]['siteNode'] = $this->siteRepository->findOneByNodeName($siteNodeName);
                    }
                    $sites[$siteNodeName]['documents'][$documentPath]['documentNode'] = $document;
                    $change = array('node' => $node);
                    if ($node->getNodeType()->isOfType('TYPO3.Neos:Node')) {
                        $change['configuration'] = $node->getNodeType()->getFullConfiguration();
                    }
                    $sites[$siteNodeName]['documents'][$documentPath]['changes'][$relativePath] = $change;
                }
            }
        }

        $liveContext = $this->contextFactory->create(array(
            'workspaceName' => 'live'
        ));

        ksort($sites);
        foreach ($sites as $siteKey => $site) {
            foreach ($site['documents'] as $documentKey => $document) {
                foreach ($document['changes'] as $changeKey => $change) {
                    $liveNode = $liveContext->getNodeByIdentifier($change['node']->getIdentifier());
                    $sites[$siteKey]['documents'][$documentKey]['changes'][$changeKey]['isNew'] = is_null($liveNode);
                    $sites[$siteKey]['documents'][$documentKey]['changes'][$changeKey]['isMoved'] = $liveNode && $change['node']->getPath() !== $liveNode->getPath();
                }
            }
            ksort($sites[$siteKey]['documents']);
        }

        $workspaces = array();
        foreach ($this->workspaceRepository->findAll() as $workspaceInstance) {
            array_push($workspaces, array(
                'workspaceNode' => $workspaceInstance,
                'unpublishedNodesCount' => $this->publishingService->getUnpublishedNodesCount($workspaceInstance)
            ));
        }

        $this->view->assignMultiple(array(
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'sites' => $sites
        ));
    }

    /**
     * @param \TYPO3\TYPO3CR\Domain\Model\NodeInterface $node
     * @return void
     */
    public function publishNodeAction(\TYPO3\TYPO3CR\Domain\Model\NodeInterface $node)
    {
        $this->publishingService->publishNode($node);
        $this->addFlashMessage('Node has been published', 'Node published', null, array(), 1412421581);
        $this->redirect('index');
    }

    /**
     * @param \TYPO3\TYPO3CR\Domain\Model\NodeInterface $node
     * @return void
     */
    public function discardNodeAction(\TYPO3\TYPO3CR\Domain\Model\NodeInterface $node)
    {
        // Hint: we cannot use $node->remove() here, as this removes the node recursively (but we just want to *discard changes*)
        $this->publishingService->discardNode($node);
        $this->addFlashMessage('Node has been discarded', 'Node discarded', null, array(), 1412420292);
        $this->redirect('index');
    }

    /**
     * @param array<\TYPO3\TYPO3CR\Domain\Model\NodeInterface> $nodes
     * @param string $action
     * @return void
     * @throws \RuntimeException
     */
    public function publishOrDiscardNodesAction(array $nodes, $action)
    {
        $propertyMappingConfiguration = $this->propertyMappingConfigurationBuilder->build();
        $propertyMappingConfiguration->setTypeConverterOption('TYPO3\TYPO3CR\TypeConverter\NodeConverter', \TYPO3\TYPO3CR\TypeConverter\NodeConverter::REMOVED_CONTENT_SHOWN, true);
        foreach ($nodes as $key => $node) {
            $nodes[$key] = $this->propertyMapper->convert($node, 'TYPO3\TYPO3CR\Domain\Model\NodeInterface', $propertyMappingConfiguration);
        }
        switch ($action) {
            case 'publish':
                foreach ($nodes as $node) {
                    $this->publishingService->publishNode($node);
                }
                $this->addFlashMessage('Selected changes have been published', null, null, array(), 412420736);
            break;
            case 'discard':
                $this->publishingService->discardNodes($nodes);
                $this->addFlashMessage('Selected changes have been discarded', null, null, array(), 412420851);
            break;
            default:
                throw new \RuntimeException('Invalid action "' . htmlspecialchars($action) . '" given.', 1346167441);
        }

        $this->redirect('index');
    }

    /**
     * @param Workspace $workspace
     * @return void
     */
    public function publishWorkspaceAction(Workspace $workspace)
    {
        $liveWorkspace = $this->workspaceRepository->findOneByName('live');
        $workspace->publish($liveWorkspace);
        $this->addFlashMessage('Changes in workspace "%s" have been published', 'Changes published', Message::SEVERITY_OK, array(htmlspecialchars($workspace->getName())), 1412420808);
        $this->redirect('index');
    }

    /**
     * @param Workspace $workspace
     * @return void
     */
    public function discardWorkspaceAction(Workspace $workspace)
    {
        $unpublishedNodes = $this->publishingService->getUnpublishedNodes($workspace);
        $this->publishingService->discardNodes($unpublishedNodes);
        $this->addFlashMessage('Changes in workspace "%s" have been discarded', 'Changes discarded', Message::SEVERITY_OK, array(htmlspecialchars($workspace->getName())), 1412420835);
        $this->redirect('index');
    }
}
