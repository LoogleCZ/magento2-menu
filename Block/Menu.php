<?php

namespace Snowdog\Menu\Block;

use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\Search\SearchCriteriaFactory;
use Magento\Framework\App\Cache\Type\Block;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\Escaper;
use Snowdog\Menu\Api\MenuRepositoryInterface;
use Snowdog\Menu\Api\NodeRepositoryInterface;
use Snowdog\Menu\Model\Menu\Node\Image\File as ImageFile;
use Snowdog\Menu\Model\NodeTypeProvider;
use Snowdog\Menu\Model\TemplateResolver;
use Magento\Store\Model\Store;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @api
 */
class Menu extends Template implements DataObject\IdentityInterface
{
    /**
     * @var MenuRepositoryInterface
     */
    private $menuRepository;

    /**
     * @var NodeRepositoryInterface
     */
    private $nodeRepository;

    /**
     * @var NodeTypeProvider
     */
    private $nodeTypeProvider;

    private $nodes;

    private $menu = null;

    /**
     * @var SearchCriteriaFactory
     */
    private $searchCriteriaFactory;

    /**
     * @var FilterGroupBuilder
     */
    private $filterGroupBuilder;
    /**
     * @var EventManager
     */
    private $eventManager;

    /**
     * @var TemplateResolver
     */
    private $templateResolver;

    /**
     * @var ImageFile
     */
    private $imageFile;

    /**
     * @var string
     */
    private $submenuTemplate;

    /**
     * @var string
     */
    protected $_template = 'Snowdog_Menu::menu.phtml';

    /**
     * @var string
     */
    protected $baseSubmenuTemplate = 'Snowdog_Menu::menu/sub_menu.phtml';

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Template\Context $context,
        EventManager $eventManager,
        MenuRepositoryInterface $menuRepository,
        NodeRepositoryInterface $nodeRepository,
        NodeTypeProvider $nodeTypeProvider,
        SearchCriteriaFactory $searchCriteriaFactory,
        FilterGroupBuilder $filterGroupBuilder,
        TemplateResolver $templateResolver,
        ImageFile $imageFile,
        Escaper $escaper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->menuRepository = $menuRepository;
        $this->nodeRepository = $nodeRepository;
        $this->nodeTypeProvider = $nodeTypeProvider;
        $this->searchCriteriaFactory = $searchCriteriaFactory;
        $this->filterGroupBuilder = $filterGroupBuilder;
        $this->eventManager = $eventManager;
        $this->templateResolver = $templateResolver;
        $this->imageFile = $imageFile;
        $this->escaper = $escaper;
        $this->setTemplate($this->getMenuTemplate($this->_template));
        $this->submenuTemplate = $this->getSubmenuTemplate();
    }

    /**
     * Return unique ID(s) for each object in system
     *
     * @return string[]
     */
    public function getIdentities()
    {
        return [
            \Snowdog\Menu\Api\Data\MenuInterface::CACHE_TAG . '_' . $this->loadMenu()->getId(),
            Block::CACHE_TAG,
            \Snowdog\Menu\Api\Data\MenuInterface::CACHE_TAG
        ];
    }

    protected function getCacheLifetime()
    {
        return 60*60*24*365;
    }

    /**
     * @return \Snowdog\Menu\Model\Menu
     */
    private function loadMenu()
    {
        if ($this->menu === null) {
            $identifier = $this->getData('menu');
            $storeId = $this->_storeManager->getStore()->getId();
            $this->menu = $this->menuRepository->get($identifier, $storeId);

            if (empty($this->menu->getData())) {
                $this->menu = $this->menuRepository->get($identifier, Store::DEFAULT_STORE_ID);
            }
        }

        return $this->menu;
    }

    /**
     * @return \Snowdog\Menu\Model\Menu|null
     */
    public function getMenu()
    {
        $menu = $this->loadMenu();
        if (!$menu->getMenuId()) {
            return null;
        }

        return $menu;
    }

    public function getCacheKeyInfo()
    {
        $info = [
            \Snowdog\Menu\Api\Data\MenuInterface::CACHE_TAG,
            'menu_' . $this->loadMenu()->getId(),
            'store_' . $this->_storeManager->getStore()->getId(),
            'template_' . $this->getTemplate()
        ];

        $nodeCacheKeyInfo = $this->getNodeCacheKeyInfo();
        if ($nodeCacheKeyInfo) {
            $info = array_merge($info, $nodeCacheKeyInfo);
        }

        return $info;
    }

    /**
     * @return array
     */
    private function getNodeCacheKeyInfo()
    {
        $info = [];
        $nodeType = '';
        $request = $this->getRequest();

        switch ($request->getRouteName()) {
            case 'catalog':
                $nodeType = 'category';
                break;
        }

        $transport = [
            'node_type' => $nodeType,
            'request' => $request
        ];

        $transport = new DataObject($transport);
        $this->eventManager->dispatch(
            'snowdog_menu_cache_node_type',
            ['transport' => $transport]
        );

        if ($transport->getNodeType()) {
            $nodeType = $transport->getNodeType();
        }

        if ($nodeType) {
            $info = $this->getNodeTypeProvider($nodeType)->getNodeCacheKeyInfo();
        }

        if ($this->getParentNode()) {
            $info[] = 'parent_node_' . $this->getParentNode()->getNodeId();
        }

        return $info;
    }

    public function getMenuHtml($level = 0, $parent = null)
    {
        $nodes = $this->getNodes($level, $parent);
        $html = '';
        $i = 0;
        foreach ($nodes as $node) {
            $children = $this->getMenuHtml($level + 1, $node);
            $classes = [
                'level' . $level,
                $node->getClasses() ?: '',
            ];
            if (!empty($children)) {
                $classes[] = 'parent';
            }
            if ($i == 0) {
                $classes[] = 'first';
            }
            if ($i == count($nodes) - 1) {
                $classes[] = 'last';
            }
            if ($level == 0) {
                $classes[] = 'level-top';
            }
            $html .= '<li class="' . implode(' ', $classes) . '">';
            $html .= $this->renderNode($node, $level);
            if (!empty($children)) {
                $html .= '<ul class="level' . $level . ' submenu">';
                $html .= $children;
                $html .= '</ul>';
            }
            $html .= '</li>';
            ++$i;
        }
        return $html;
    }

    /**
     * @param string $nodeType
     * @return bool
     */
    public function isViewAllLinkAllowed($nodeType)
    {
        return $this->getNodeTypeProvider($nodeType)->isViewAllLinkAllowed();
    }

    /**
     * @param NodeRepositoryInterface $node
     * @return string
     */
    public function renderViewAllLink($node)
    {
        return $this->getMenuNodeBlock($node)
            ->setIsViewAllLink(true)
            ->toHtml();
    }

    /**
     * @param NodeRepositoryInterface $node
     * @return string
     */
    public function renderMenuNode($node)
    {
        return $this->getMenuNodeBlock($node)->toHtml();
    }

    /**
     * @param array $nodes
     * @param NodeRepositoryInterface $parentNode
     * @param int $level
     * @return string
     */
    public function renderSubmenu($nodes, $parentNode, $level = 0)
    {
        return $nodes
            ? $this->getSubmenuBlock($nodes, $parentNode, $level)->toHtml()
            : '';
    }

    /**
     * @param int $level
     * @param NodeRepositoryInterface|null $parent
     * @return array
     */
    public function getNodesTree($level = 0, $parent = null)
    {
        $nodesTree = [];
        $nodes = $this->getNodes($level, $parent);

        foreach ($nodes as $node) {
            $nodesTree[] = [
                'node' => $node,
                'children' => $this->getNodesTree($level + 1, $node)
            ];
        }

        return $nodesTree;
    }

    /**
     * @param string $nodeType
     * @return \Snowdog\Menu\Api\NodeTypeInterface
     */
    public function getNodeTypeProvider($nodeType)
    {
        return $this->nodeTypeProvider->getProvider($nodeType);
    }

    public function getNodes($level = 0, $parent = null)
    {
        if (empty($this->nodes)) {
            $this->fetchData();
        }
        if (!isset($this->nodes[$level])) {
            return [];
        }
        $parentId = $parent !== null ? $parent['node_id'] : 0;
        if (!isset($this->nodes[$level][$parentId])) {
            return [];
        }
        return $this->nodes[$level][$parentId];
    }

    /**
     * Builds HTML tag attributes from an array of attributes data
     *
     * @param array $array
     * @return string
     */
    public function buildAttrFromArray(array $array)
    {
        $attributes = [];

        foreach ($array as $attribute => $data) {
            if (is_array($data)) {
                $data = implode(' ', $data);
            }

            $attributes[] = $attribute . '="' . $this->escaper->escapeHtml($data) . '"';
        }

        return $attributes ? ' ' . implode(' ', $attributes) : '';
    }

    /**
     * @param string $defaultClass
     * @return string
     */
    public function getMenuCssClass($defaultClass = '')
    {
        $menu = $this->getMenu();

        if ($menu === null) {
            return $defaultClass;
        }

        return $menu->getCssClass();
    }

    /**
     * @param NodeRepositoryInterface $node
     * @return Template
     */
    private function getMenuNodeBlock($node)
    {
        $nodeBlock = $this->getNodeTypeProvider($node->getType());

        $level = $node->getLevel();
        $isRoot = 0 == $level;
        $nodeBlock->setId($node->getNodeId())
            ->setTitle($node->getTitle())
            ->setLevel($level)
            ->setIsRoot($isRoot)
            ->setIsParent((bool) $node->getIsParent())
            ->setIsViewAllLink(false)
            ->setContent($node->getContent())
            ->setNodeClasses($node->getClasses())
            ->setMenuClass($this->getMenu()->getCssClass())
            ->setMenuCode($this->getData('menu'))
            ->setTarget($node->getTarget())
            ->setImage($node->getImage())
            ->setImageUrl($node->getImage() ? $this->imageFile->getUrl($node->getImage()) : null)
            ->setImageAltText($node->getImageAltText())
            ->setCustomTemplate($node->getNodeTemplate())
            ->setAdditionalData($node->getAdditionalData())
            ->setSelectedItemId($node->getSelectedItemId());

        return $nodeBlock;
    }

    /**
     * @param array $nodes
     * @param NodeRepositoryInterface $parentNode
     * @param int $level
     * @return Menu
     */
    private function getSubmenuBlock($nodes, $parentNode, $level = 0)
    {
        $block = clone $this;
        $submenuTemplate = $parentNode->getSubmenuTemplate();
        $submenuTemplate = $submenuTemplate
            ? 'Snowdog_Menu::' . $this->getMenu()->getIdentifier() . "/menu/custom/sub_menu/{$submenuTemplate}.phtml"
            : $this->submenuTemplate;

        $block->setSubmenuNodes($nodes)
            ->setParentNode($parentNode)
            ->setLevel($level);

        $block->setTemplateContext($block);
        $block->setTemplate($submenuTemplate);

        return $block;
    }

    private function fetchData()
    {
        $nodes = $this->nodeRepository->getByMenu($this->loadMenu()->getId());
        $result = [];
        $types = [];
        foreach ($nodes as $node) {
            if (!$node->getIsActive()) {
                continue;
            }

            $level = $node->getLevel();
            $parent = $node->getParentId() ?: 0;
            if (!isset($result[$level])) {
                $result[$level] = [];
            }
            if (!isset($result[$level][$parent])) {
                $result[$level][$parent] = [];
            }
            $result[$level][$parent][] = $node;
            $type = $node->getType();
            if (!isset($types[$type])) {
                $types[$type] = [];
            }
            $types[$type][] = $node;
        }
        $this->nodes = $result;

        foreach ($types as $type => $nodes) {
            $this->nodeTypeProvider->prepareData($type, $nodes);
        }
    }

    private function renderNode($node, $level)
    {
        $type = $node->getType();
        return $this->nodeTypeProvider->render($type, $node->getId(), $level);
    }

    /**
     * @param string $template
     * @return string
     */
    private function getMenuTemplate($template)
    {
        return $this->templateResolver->getMenuTemplate(
            $this,
            $this->getData('menu'),
            $template
        );
    }

    /**
     * @return string
     */
    private function getSubmenuTemplate()
    {
        $baseSubmenuTemplate = $this->baseSubmenuTemplate;
        if ($this->getData('subMenuTemplate')) {
            $baseSubmenuTemplate = $this->getData('subMenuTemplate');
        }

        return $this->getMenuTemplate($baseSubmenuTemplate);
    }
}
