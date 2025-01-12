<?php

/*
 * This file is part of the Chameleon System (https://www.chameleonsystem.com).
 *
 * (c) ESONO AG (https://www.esono.de)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use ChameleonSystem\CoreBundle\CoreEvents;
use ChameleonSystem\CoreBundle\Event\ChangeNavigationTreeNodeEvent;
use ChameleonSystem\CoreBundle\ServiceLocator;
use ChameleonSystem\CoreBundle\TableEditor\NestedSet\NestedSetHelperFactoryInterface;
use ChameleonSystem\CoreBundle\TableEditor\NestedSet\NestedSetHelperInterface;
use ChameleonSystem\CoreBundle\Util\InputFilterUtilInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Treemanagement Module for the CMS Navigation tree.
 *
 * @deprecated since 6.3.8 - use NavigationTree instead
 */
class CMSModulePageTree extends TCMSModelBase
{
    /**
     * the root node id.
     *
     * @var string
     */
    protected $iRootNode = null;

    /**
     * the root node object.
     *
     * @var TCMSTreeNode
     */
    protected $oRootNode = null;

    /**
     * the mysql tablename of the tree.
     *
     * @var string
     */
    protected $treeTable = 'cms_tree';

    /**
     * nodes that should not be assignable or that should have only a
     * restricted context menu.
     *
     * @var array
     */
    protected $aRestrictedNodes = array();

    /**
     * array of all currently open tree nodes.
     *
     * @var array
     */
    protected $aOpenTreeNodes = array();

    /**
     * count of portals in this CMS
     * tree level pre rendering is based on this.
     *
     * @var int
     */
    protected $iPortalCount = 1;

    /**
     * {@inheritdoc}
     */
    public function Init()
    {
        parent::Init();
        $this->data['sTreeHTML'] = '';
        $this->data['iTreeNodeCount'] = 0;

        $this->data['treeTableID'] = TTools::GetCMSTableId('cms_tree');
        $this->data['treeNodeTableID'] = TTools::GetCMSTableId('cms_tree_node');
    }

    /**
     * {@inheritdoc}
     */
    public function &Execute()
    {
        parent::Execute();

        $inputFilterUtil = $this->getInputFilterUtil();
        $this->data['isInIframe'] = false;

        $isInIframe = $inputFilterUtil->getFilteredInput('isInIframe');
        if (null !== $isInIframe) {
            $this->data['isInIframe'] = true;
        }

        $this->data['rootNodeName'] = 'Root';

        $sPageTableId = TTools::GetCMSTableId('cms_tpl_page');
        $this->data['tplPageTableID'] = $sPageTableId;

        $this->data['showAssignDialog'] = true;
        if ($this->global->UserDataExists('noassign') && '1' == $this->global->GetUserData('noassign')) {
            $this->data['showAssignDialog'] = false;
        }

        $this->data['dataID'] = null;

        if ($this->global->UserDataExists('id')) {
            $this->data['dataID'] = $this->global->GetUserData('id');
        }

        $nodeID = $inputFilterUtil->getFilteredGetInput('rootID', TCMSTreeNode::TREE_ROOT_ID);
        $this->data['rootID'] = $nodeID;
        $this->iRootNode = $nodeID;
        $this->GetRootNodeName($nodeID);
        $this->LoadTreeState();
        $this->aRestrictedNodes = $this->GetPortalNavigationStartNodes();

        // Check if we have more than 3 portals (needed because of performance issues in tree pre-rendering)
        $oPortalList = TdbCmsPortalList::GetList();
        $this->iPortalCount = $oPortalList->Length();

        $this->RenderTree($this->oRootNode->id, $this->oRootNode, 0);

        if ($this->global->UserDataExists('table')) {
            $this->data['table'] = $this->global->GetUserData('table');
        }

        if ($this->global->UserDataExists('table') && $this->data['dataID']) {
            $databaseConnection = $this->getDatabaseConnection();
            $quotedTable = $databaseConnection->quoteIdentifier(str_replace('`', '', $this->global->GetUserData('table', TCMSUserInput::FILTER_SAFE_TEXT)));
            $quotedId = $databaseConnection->quote($this->data['dataID']);
            $checkQuery = "SELECT `name` FROM $quotedTable WHERE `id` = $quotedId";
            $checkResult = MySqlLegacySupport::getInstance()->query($checkQuery);
            $row = MySqlLegacySupport::getInstance()->fetch_assoc($checkResult);
            $this->data['dataName'] = $row['name'];
        }

        return $this->data;
    }

    protected function DefineInterface()
    {
        parent::DefineInterface();
        $externalFunctions = array('SetConnection', 'MoveNode', 'DeleteNode', 'GetSubTree', 'GetTransactionDetails');
        $this->methodCallAllowed = array_merge($this->methodCallAllowed, $externalFunctions);
    }

    /**
     * fetches the name of the root node of the tree and fills $this->oRootNode with the CMSTreeNode object.
     *
     * @param int $nodeID
     */
    protected function GetRootNodeName($nodeID)
    {
        $oCMSTreeNode = TdbCmsTree::GetNewInstance(); /** @var $oCMSTreeNode TCMSTreeNode */
        $oCMSTreeNode->SetLanguage(TdbCmsUser::GetActiveUser()->GetCurrentEditLanguageID());
        $oCMSTreeNode->Load($nodeID);

        $this->oRootNode = &$oCMSTreeNode;
        $this->data['rootNodeName'] = $oCMSTreeNode->sqlData['name'];
    }

    /**
     * loads the open tree nodes from cookie.
     */
    protected function LoadTreeState()
    {
        if (array_key_exists('chameleonTreeState', $_COOKIE) && !empty($_COOKIE['chameleonTreeState'])) {
            $this->aOpenTreeNodes = explode(',', $_COOKIE['chameleonTreeState']);
        }
    }

    /**
     * renders all children of a node
     * is called via ajax.
     *
     * @return string
     */
    public function GetSubTree()
    {
        $sHTML = '';
        if ($this->global->UserDataExists('nodeID')) {
            $iParentID = $this->global->GetUserData('nodeID');
            if ($this->global->UserDataExists('id')) {
                $this->data['dataID'] = $this->global->GetUserData('id');
            }

            $databaseConnection = $this->getDatabaseConnection();
            $quotedTreeTable = $databaseConnection->quoteIdentifier($this->treeTable);
            $quotedParentId = $databaseConnection->quote($iParentID);
            $quotedSortField = $databaseConnection->quoteIdentifier($this->GetSortFieldName($iParentID));
            $sPortalCondition = $this->GetChildrenPortalCondition();

            $query = "SELECT *
                      FROM $quotedTreeTable
                      WHERE `parent_id` = $quotedParentId
                      {$sPortalCondition}
                      ORDER BY $quotedSortField";

            $oTreeNodes = TdbCmsTreeList::GetList($query, TdbCmsUser::GetActiveUser()->GetCurrentEditLanguageID());

            if ($oTreeNodes->Length() > 0) {
                // render next tree level
                while ($oTreeNode = $oTreeNodes->Next()) {
                    $this->RenderTree($oTreeNode->id, $oTreeNode, 0, false);
                }

                $sHTML = $this->data['sTreeHTML'];
            }
        }

        return $sHTML;
    }

    /**
     * forms an indicator icon with an optional link.
     *
     * @param string      $iconUrl
     * @param string      $iconIdentifier
     * @param string|null $linkUrl
     *
     * @return string
     */
    private function getNodeIndicatorIcon($iconUrl, $iconIdentifier, $linkUrl = null)
    {
        $staticIconUrl = TGlobal::OutHTML(TGlobal::GetStaticURLToWebLib($iconUrl));
        $styleSnippet = sprintf('style="background-image: url(\'%s\')" class="nodeIndicatorIcon %s"', $staticIconUrl, $iconIdentifier);
        $spanSnippet = sprintf('<span %s></span>', $styleSnippet);
        $anchorSnippet = sprintf('<a %s href="%s" target="_blank"></a>', $styleSnippet, $linkUrl);
        // Return only span without link if no link url was supplied.
        if (null === $linkUrl) {
            return $spanSnippet;
        }
        // Return span wrapped in link from supplied url.
        return $anchorSnippet;
    }

    /**
     * get children of current tree node.
     *
     * @param string     $iParentID
     * @param TdbCmsTree $oParentTreeNode
     * @param int        $level
     * @param bool       $allowAjax
     */
    protected function RenderTree($iParentID = null, $oParentTreeNode = null, $level = 0, $allowAjax = true)
    {
        if (null === $iParentID) {
            $iParentID = $this->iRootNode;
        }

        $sListClasses = '';

        if (!$this->global->UserDataExists('nodeID')) {
            if (0 == $level) {
                $sListClasses = 'root';
            }
        }

        $sSpacing = '  ';
        str_pad($sSpacing, ($level + 1) * 2, '  ');

        $sSpanClass = '';
        $sSpanMenuClass = 'standard';
        if (!$this->global->UserDataExists('nodeID')) {
            if (0 == $level) {
                $sSpanMenuClass = 'rootRightClickMenu';
            }
        }

        $sIconHTML = '';

        $oCmsUser = TCMSUser::GetActiveUser();
        $oEditLanguage = $oCmsUser->GetCurrentEditLanguageObject();
        $parentNodeHidden = false;

        $oParentTreeNode->SetLanguage($oEditLanguage->id);
        if (true == $oParentTreeNode->fieldHidden) {
            $sIconHTML .= $this->getNodeIndicatorIcon('/images/tree/hidden.png', 'iconHidden');
            $parentNodeHidden = true;
        }

        if (!empty($oParentTreeNode->sqlData['link'])) {
            $sIconHTML .= $this->getNodeIndicatorIcon('/images/tree/icon_external_link.gif', 'iconExternalLink', $oParentTreeNode->sqlData['link']);
        }

        $sIsTranslatedIdent = '';
        if (CMS_TRANSLATION_FIELD_BASED_EMPTY_TRANSLATION_FALLBACK_TO_BASE_LANGUAGE) {
            $oCmsConfig = TCMSConfig::GetInstance();
            $oDefaultLang = $oCmsConfig->GetFieldTranslationBaseLanguage();
            if ($oDefaultLang && $oDefaultLang->id != $oEditLanguage->id) {
                if (empty($oParentTreeNode->sqlData['name__'.$oEditLanguage->fieldIso6391])) {
                    $sIsTranslatedIdent = '<img src="'.TGlobal::GetStaticURL('chameleon/blackbox/images/tree/folder_edit.png').'" width="12" align="absmiddle" border="0" alt="'.TGlobal::OutHTML(TGlobal::Translate('chameleon_system_core.cms_module_page_tree.not_translated')).'" />';
                }
            }
        }
        $sTreeNodeName = $oParentTreeNode->GetName();
        if (empty($sTreeNodeName)) {
            $sTreeNodeName = TGlobal::Translate('chameleon_system_core.text.unnamed_record');
        }

        $sPageTag = '';
        $sPrimaryPageID = $oParentTreeNode->GetLinkedPage(true);

        $aPages = array();

        $bCurrentPageIsConnectedToThisNode = false;
        $oConnectedPages = $oParentTreeNode->GetAllLinkedPages();
        $bFoundSecuredPage = false;
        while ($connectedPage = $oConnectedPages->Next()) {
            $aPages[] = $connectedPage->id;

            if (!$parentNodeHidden
                && !$bFoundSecuredPage
                && (true === $connectedPage->fieldExtranetPage
                && false === $oParentTreeNode->fieldShowExtranetPage)) {
                $sIconHTML .= $this->getNodeIndicatorIcon('/images/tree/hidden.png', 'iconHidden');
                $bFoundSecuredPage = true;
            }

            if (true === $connectedPage->fieldExtranetPage) {
                $sIconHTML .= $this->getNodeIndicatorIcon('/images/tree/lock.png', 'iconRestricted');
            }
        }

        if (count($aPages) > 0) {
            if (array_key_exists('dataID', $this->data) && in_array($this->data['dataID'], $aPages)) {
                $bCurrentPageIsConnectedToThisNode = true;
            }
            if (false !== $sPrimaryPageID) {
                $sPageTag = ' espageid="'.TGlobal::OutHTML($sPrimaryPageID).'"';
            }
            if ($bCurrentPageIsConnectedToThisNode) {
                $sSpanClass .= ' activeConnectedNode';

                // kill all current page ids from $aPages and check if we have other pages connected
                $aKeys = array_keys($aPages, $this->data['dataID']);
                foreach ($aKeys as $key) {
                    unset($aPages[$key]);
                }

                if (count($aPages) > 0) {
                    $sSpanClass .= ' otherConnectedNode';
                }
            } else {
                $sSpanClass .= ' otherConnectedNode';
            }
        }

        $lastStateOpen = false;
        // check if last node state was "open"
        if ($level <= 2 || in_array('node'.$iParentID, $this->aOpenTreeNodes)) {
            $lastStateOpen = true;
            $sListClasses .= ' folder-open';
        }

        if (in_array($oParentTreeNode->id, $this->aRestrictedNodes)) {
            $sSpanMenuClass = 'restrictedRightClickMenu';
        }

        ++$this->data['iTreeNodeCount']; // count the tree nodes
        $this->data['sTreeHTML'] .= $sSpacing.'<li class="'.$sListClasses.'" id="node'.$oParentTreeNode->sqlData['cmsident'].'" esrealid="'.$iParentID.'" '.$sPageTag.'>
        <span class="'.$sSpanClass.' '.$sSpanMenuClass.'">'.TGlobal::OutHTML($sTreeNodeName).$sIsTranslatedIdent.'</span>'.$sIconHTML;

        $databaseConnection = $this->getDatabaseConnection();
        $quotedTreeTable = $databaseConnection->quoteIdentifier($this->treeTable);
        $quotedParentId = $databaseConnection->quote($iParentID);
        $quotedSortField = $databaseConnection->quoteIdentifier($this->GetSortFieldName($iParentID));
        $sPortalCondition = $this->GetChildrenPortalCondition();

        $query = "SELECT *
                  FROM $quotedTreeTable
                  WHERE `parent_id` = $quotedParentId
                  {$sPortalCondition}
                  ORDER BY $quotedSortField";

        $oTreeNodes = TdbCmsTreeList::GetList($query, TdbCmsUser::GetActiveUser()->GetCurrentEditLanguageID());
        ++$level;

        if ($oTreeNodes->Length() > 0) {
            $this->data['sTreeHTML'] .= "\n".$sSpacing.'  <ul';

            // change to ajax

            if ($this->iPortalCount > 3) {
                $iMaxLevel = 4;
            } else {
                $iMaxLevel = 5;
            }

            if ($level >= $iMaxLevel && $allowAjax && !$lastStateOpen) {
                $this->data['sTreeHTML'] .= ' class="ajax">\n';

                $ajaxURL = PATH_CMS_CONTROLLER.'?'.TTools::GetArrayAsURLForJavascript(array('pagedef' => 'CMSModulePageTreePlain', 'module_fnc' => array('contentmodule' => 'ExecuteAjaxCall'), '_fnc' => 'GetSubTree', 'tableid' => $this->data['treeTableID'], 'sOutputMode' => 'Plain', 'nodeID' => $iParentID));
                if (isset($this->data['dataID'])) {
                    $ajaxURL .= '&id='.$this->data['dataID'];
                }

                $this->data['sTreeHTML'] .= '<li>{url:'.$ajaxURL.'}';
            } else {
                $this->data['sTreeHTML'] .= ">\n";
                // render next tree level
                while ($oTreeNode = $oTreeNodes->Next()) {
                    $this->RenderTree($oTreeNode->id, $oTreeNode, $level);
                }
            }
        }

        if ($oTreeNodes->Length() > 0) {
            $this->data['sTreeHTML'] .= $sSpacing."  </ul>\n";
        }
        $this->data['sTreeHTML'] .= $sSpacing."</li>\n";
    }

    /**
     * Fetches a list of all restricted nodes (of all portals)
     * a restricted node is a startnavigation nodes of a portal.
     *
     * @return array
     */
    protected function GetPortalNavigationStartNodes()
    {
        $oPortalList = TdbCmsPortalList::GetList(null, TdbCmsUser::GetActiveUser()->GetCurrentEditLanguageID());

        $aRestrictedNodes = array();
        while ($oPortal = $oPortalList->Next()) {
            $aRestrictedNodes[] = $oPortal->fieldMainNodeTree;
            $oNavigationList = $oPortal->GetFieldPropertyNavigationsList();
            while ($oNavigation = $oNavigationList->Next()) {
                $aRestrictedNodes[] = $oNavigation->fieldTreeNode;
            }
        }

        return $aRestrictedNodes;
    }

    /**
     * get the portal node ids the user is NOT allowed to view, and exclude them from the list.
     *
     * @return string
     */
    protected function GetChildrenPortalCondition()
    {
        static $internalCache = null;
        if (null !== $internalCache) {
            $aPortalExcludeList = $internalCache;
        } else {
            $oUser = &TCMSUser::GetActiveUser();
            $sPortalList = $oUser->oAccessManager->user->portals->PortalList();
            $query = 'SELECT * FROM `cms_portal`';
            if (false !== $sPortalList) {
                $query .= ' WHERE `id` NOT IN ('.$sPortalList.')';
            }
            $aPortalExcludeList = array();
            $portalRes = MySqlLegacySupport::getInstance()->query($query);
            while ($portal = MySqlLegacySupport::getInstance()->fetch_assoc($portalRes)) {
                if (!empty($portal['main_node_tree'])) {
                    $aPortalExcludeList[] = $portal['main_node_tree'];
                }
            }
            $internalCache = $aPortalExcludeList;
        }

        $sPortalCondition = '';
        if (count($aPortalExcludeList) > 0) {
            $databaseConnection = $this->getDatabaseConnection();
            $quotedTableName = $databaseConnection->quoteIdentifier($this->treeTable);
            $portalExcludeListString = implode(',', array_map(array($databaseConnection, 'quote'), $aPortalExcludeList));
            $sPortalCondition .= " AND $quotedTableName.`id` NOT IN ($portalExcludeListString)";
        }

        return $sPortalCondition;
    }

    /**
     * Get the sort field name for the active language.
     * Get default field name if active language is same as portal main language,
     * field is not translateable or field based translation is off.
     *
     * @param string $sTreeId Id of an existing tree (need to check if language field exists)
     *
     * @return string
     */
    protected function GetSortFieldName($sTreeId)
    {
        $sEntrySortField = 'entry_sort';
        if (TdbCmsTree::CMSFieldIsTranslated('entry_sort')) {
            $sLanguagePrefix = TGlobal::GetLanguagePrefix($user = TdbCmsUser::GetActiveUser()->GetCurrentEditLanguageID());
            if ('' !== $sLanguagePrefix) {
                $sEntrySortField .= '__'.$sLanguagePrefix;
            }

            return $sEntrySortField;
        }

        return $sEntrySortField;
    }

    /**
     * moves node to new position and updates all relevant node positions
     * and the parent node connection if changed.
     *
     * @return bool
     */
    public function MoveNode()
    {
        $returnVal = false;
        if ($this->global->UserDataExists('tableid') && $this->global->UserDataExists('nodeID') && $this->global->UserDataExists('parentNodeID') && $this->global->UserDataExists('position')) {
            $updatedNodes = array();
            $iTableID = $this->global->GetUserData('tableid');
            $iNodeID = $this->global->GetUserData('nodeID');
            $iParentNodeID = $this->global->GetUserData('parentNodeID');
            $iPosition = $this->global->GetUserData('position');
            if (!empty($iTableID) && !empty($iNodeID) && 'undefined' != $iNodeID && !empty($iParentNodeID)) { // prevent saving if node was dropped on empty space instead of an other node
                $oTableEditor = new TCMSTableEditorManager();
                $sEntrySortField = $this->GetSortFieldName($iParentNodeID);
                $databaseConnection = $this->getDatabaseConnection();
                $quotedTreeTable = $databaseConnection->quoteIdentifier($this->treeTable);
                $quotedParentNodeId = $databaseConnection->quote($iParentNodeID);
                if (0 == $iPosition) { // set every other node pos+1
                    $query = "SELECT * FROM $quotedTreeTable WHERE `parent_id` = $quotedParentNodeId";
                    $oCmsTreeList = TdbCmsTreeList::GetList($query);
                    while ($oCmsTreeNode = &$oCmsTreeList->Next()) {
                        $oTableEditor->Init($iTableID, $oCmsTreeNode->id);
                        $newSortOrder = $oTableEditor->oTableEditor->oTable->sqlData[$sEntrySortField] + 1;
                        $oTableEditor->SaveField('entry_sort', $newSortOrder);
                        $updatedNodes[] = $oCmsTreeNode;
                    }

                    $oTableEditor->Init($iTableID, $iNodeID);
                    $oTableEditor->SaveField('entry_sort', 0);
                    $oTableEditor->SaveField('parent_id', $iParentNodeID);
                } else {
                    $quotedNodeId = $databaseConnection->quote($iNodeID);
                    $quotedEntrySortField = $databaseConnection->quoteIdentifier($sEntrySortField);
                    $query = "SELECT * FROM $quotedTreeTable WHERE `parent_id` = $quotedParentNodeId AND `id` != $quotedNodeId ORDER BY $quotedEntrySortField  ASC";
                    $oCmsTreeList = &TdbCmsTreeList::GetList($query, TdbCmsUser::GetActiveUser()->GetCurrentEditLanguageID());

                    $count = 0;
                    while ($oCmsTree = &$oCmsTreeList->Next()) {
                        if ($iPosition == $count) { // skip new position of moved node
                            ++$count;
                        }

                        $oTableEditor->Init($iTableID, $oCmsTree->id);
                        $oTableEditor->SaveField('entry_sort', $count);
                        ++$count;
                    }

                    $oTableEditor->Init($iTableID, $iNodeID);
                    $oTableEditor->SaveField('entry_sort', $iPosition);
                    $oTableEditor->SaveField('parent_id', $iParentNodeID);
                    $updatedNodes[] = $oCmsTree;
                }

                // update cache
                TCacheManager::PerformeTableChange($this->treeTable, $iNodeID);
                $this->UpdateSubtreePathCache($iNodeID);

                $returnVal = true;
            }

            $node = TdbCmsTree::GetNewInstance($iNodeID);
            $this->getNestedSetHelper()->updateNode($node);
            $this->writeSqlLog();

            $updatedNodes[] = $node;
            $event = new ChangeNavigationTreeNodeEvent($updatedNodes);
            $this->getEventDispatcher()->dispatch($event, CoreEvents::UPDATE_NAVIGATION_TREE_NODE);
        }

        return $returnVal;
    }

    /**
     * deletes a node and all subnodes.
     *
     * @return mixed - returns false or id of the node that needs to be removed from the html tree
     */
    public function DeleteNode()
    {
        $returnVal = false;
        if ($this->global->UserDataExists('tableid') && $this->global->UserDataExists('nodeID')) {
            $iTableID = $this->global->GetUserData('tableid');
            $iNodeID = $this->global->GetUserData('nodeID');
            $oTableEditor = new TCMSTableEditorManager();
            $oTableEditor->Init($iTableID, $iNodeID);
            $returnVal = $oTableEditor->oTableEditor->oTable->sqlData['cmsident'];
            $oTableEditor->Delete($iNodeID);
        }

        return $returnVal;
    }

    /**
     * update the cache of the tree path to each node of the given subtree.
     *
     * @param int $iNodeId
     */
    protected function UpdateSubtreePathCache($iNodeId)
    {
        $oNode = TdbCmsTree::GetNewInstance();
        $oNode->SetLanguage(TdbCmsUser::GetActiveUser()->GetCurrentEditLanguageID());
        $oNode->Load($iNodeId);
        $oNode->TriggerUpdateOfPathCache();
    }

    /**
     * {@inheritdoc}
     */
    public function GetHtmlHeadIncludes()
    {
        $aIncludes = parent::GetHtmlHeadIncludes();
        $aIncludes[] = '<script src="'.TGlobal::GetStaticURLToWebLib('/javascript/jquery-ui-1.12.1.custom/jquery-ui.js').'" type="text/javascript"></script>';
        $aIncludes[] = '<script src="'.TGlobal::GetStaticURLToWebLib('/javascript/jquery/simpleTree/jquery.simple.tree.js').'" type="text/javascript"></script>';
        $aIncludes[] = '<script src="'.TGlobal::GetStaticURLToWebLib('/javascript/tree/jqueryTree.js').'" type="text/javascript"></script>';
        $aIncludes[] = '<script src="'.TGlobal::GetStaticURLToWebLib('/javascript/jquery/cookie/jquery.cookie.js').'" type="text/javascript"></script>';
        $aIncludes[] = '<link href="'.TGlobal::GetPathTheme().'/css/simpleTree.css" media="screen" rel="stylesheet" type="text/css" />';
        $aIncludes[] = '<script src="'.TGlobal::GetStaticURLToWebLib('/javascript/jquery/contextmenu/contextmenu.js').'" type="text/javascript"></script>';
        $aIncludes[] = '<link href="'.TGlobal::GetPathTheme().'/css/contextmenu.css" rel="stylesheet" type="text/css" />';

        return $aIncludes;
    }

    /**
     * {@inheritdoc}
     */
    public function _AllowCache()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function _GetCacheParameters()
    {
        $parameters = parent::_GetCacheParameters();

        if (!is_array($parameters)) {
            $parameters = array();
        }

        $oCMSUser = &TCMSUser::GetActiveUser();
        $parameters['ouserid'] = $oCMSUser->id;
        $parameters['noassign'] = $this->global->GetUserData('noassign');
        $parameters['id'] = $this->global->GetUserData('id');
        $parameters['rootID'] = $this->global->GetUserData('rootID');
        $parameters['table'] = $this->global->GetUserData('table');

        return $parameters;
    }

    /**
     * {@inheritdoc}
     */
    public function _GetCacheTableInfos()
    {
        $aTableTriggerList = parent::_GetCacheTableInfos();
        if (!is_array($aTableTriggerList)) {
            $aTableTriggerList = array();
        }

        $oCMSUser = &TCMSUser::GetActiveUser();

        $aTableTriggerList[] = array('table' => 'cms_user', 'id' => $oCMSUser->id);
        $aTableTriggerList[] = array('table' => 'cms_user_cms_language_mlt', 'id' => '');
        $aTableTriggerList[] = array('table' => 'cms_user_cms_portal_mlt', 'id' => '');
        $aTableTriggerList[] = array('table' => 'cms_user_cms_role_mlt', 'id' => '');
        $aTableTriggerList[] = array('table' => 'cms_user_cms_usergroup_mlt', 'id' => '');
        $aTableTriggerList[] = array('table' => 'cms_user_cms_usergroup_mlt', 'id' => '');
        $aTableTriggerList[] = array('table' => 'cms_user_cms_usergroup_mlt', 'id' => '');
        $aTableTriggerList[] = array('table' => 'cms_role_cms_right_mlt', 'id' => '');
        $aTableTriggerList[] = array('table' => 'cms_tree', 'id' => '');
        $aTableTriggerList[] = array('table' => 'cms_tree_node', 'id' => '');

        return $aTableTriggerList;
    }

    private function writeSqlLog()
    {
        $command = <<<COMMAND
TCMSLogChange::initializeNestedSet('{$this->treeTable}', 'parent_id', 'entry_sort');
COMMAND;
        TCMSLogChange::WriteSqlTransactionWithPhpCommands('update nested set for table '.$this->treeTable, array($command));
    }

    /**
     * @return NestedSetHelperInterface
     */
    protected function getNestedSetHelper()
    {
        /** @var $factory NestedSetHelperFactoryInterface */
        $factory = ServiceLocator::get('chameleon_system_core.table_editor_nested_set_helper_factory');

        return $factory->createNestedSetHelper($this->treeTable, 'parent_id', 'entry_sort');
    }

    /**
     * @return Connection
     */
    private function getDatabaseConnection()
    {
        return ServiceLocator::get('database_connection');
    }

    /**
     * @return EventDispatcherInterface
     */
    private function getEventDispatcher()
    {
        return ServiceLocator::get('event_dispatcher');
    }

    private function getInputFilterUtil(): InputFilterUtilInterface
    {
        return ServiceLocator::get('chameleon_system_core.util.input_filter');
    }
}
