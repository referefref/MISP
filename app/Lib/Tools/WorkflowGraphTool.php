<?php

class CyclicGraphException extends Exception {}

class GraphUtil
{
    public function __construct($graphData)
    {
        $this->graph = $graphData;
        $this->numberNodes = count($this->graph);
        $this->edgeList = $this->_buildEdgeList($graphData);
        $this->properties = [];
    }

    private function _buildEdgeList($graphData): array
    {
        $list = [];
        foreach ($graphData as $node) {
            $list[(int)$node['id']] = [];
            foreach (($node['outputs'] ?? []) as $output_id => $outputs) {
                foreach ($outputs as $connections) {
                    foreach ($connections as $connection) {
                        $list[$node['id']][] = (int)$connection['node'];
                    }
                }
            }
        }
        return $list;
    }

    private function _DFSUtil($node_id, &$color, $depth=0): bool
    {
        $color[$node_id] = 'GRAY';
        foreach ($this->edgeList[$node_id] as $i) {
            if ($color[$i] == 'GRAY') {
                $this->properties[] = [$node_id, $i, __('Cycle')];
                return true;
            }
            if ($color[$i] == 'WHITE' && $this->_DFSUtil($i, $color, $depth+1)) {
                if ($depth > 0) {
                    $this->properties[] = [$node_id, $i, __('Cycle')];
                }
                return true;
            }
        }
        $color[$node_id] = 'BLACK';
        return false;
    }

    /**
     * isCyclic Return is the graph is cyclic, so if it contains a cycle.
     * 
     * A directed graph G is acyclic if and only if a depth-first search of G yields no back edges.
     * Introduction to Algorithms, third edition By Thomas H. Cormen, Charles E. Leiserson, Ronald L. Rivest, Clifford Stein
     *
     * @return array
     */
    public function isCyclic(): array
    {
        $this->properties = [];
        $color = [];
        foreach (array_keys($this->edgeList) as $node_id) {
            $color[$node_id] = 'WHITE';
        }
        foreach (array_keys($this->edgeList) as $node_id) {
            if ($color[$node_id] == 'WHITE') {
                if ($this->_DFSUtil($node_id, $color)) {
                    return [true, $this->properties];
                }
            }
        }
        return [false, []];
    }
}

class GraphWalker
{
    private $graph;
    private $WorkflowModel;
    private $startNodeID;
    private $for_path;
    private $triggersByNodeID;
    private $triggersByModuleID;
    private $cursor;

    public function __construct(array $graphData, $WorkflowModel, $startNodeID, $for_path=null)
    {
        $this->graph = $graphData;
        $this->WorkflowModel = $WorkflowModel;
        $this->startNodeID = $startNodeID;
        $this->for_path = $for_path;
        $this->triggersByNodeID = [];
        $this->triggersByModuleID = [];
        $this->setTriggers();
        if (empty($this->graph[$startNodeID])) {
            throw new Exception(__('Could not find start node %s', $startNodeID));
        }
        $this->cursor = $startNodeID;
        $this->path_type = null;
    }

    private function getModuleClass($node)
    {
        $moduleClass = $this->loaded_classes[$node['data']['module_type']][$node['data']['id']] ?? null;
        return $moduleClass;
    }

    private function setTriggers(): array
    {
        $triggers = [];
        foreach ($this->graph as $node_id => $node) {
            if ($node['data']['module_type'] == 'trigger') {
                $this->triggersByNodeID[$node_id] = $node;
                $this->triggersByModuleID[$node['data']['id']] = $node_id;
            }
        }
        return $triggers;
    }

    private function _getPathType($node_id, $path_type, $output_id)
    {
        $node = $this->graph[$node_id];
        if (!empty($this->triggersByNodeID[$node_id])) {
            return $output_id == 'output_1' ? 'blocking' : 'non-blocking';
        } elseif ($node['data']['module_type'] == 'logic' && $node['data']['id'] == 'parallel-task') {
            return 'non-blocking';
        }
        return $path_type;
    }


    private function _evaluateOutputs($node, WorkflowRoamingData $roamingData)
    {
        $allowed_outputs = ($node['outputs'] ?? []);
        if ($node['data']['module_type'] == 'logic') {
            $allowed_outputs = $this->_executeModuleLogic($node, $roamingData);
        }
        return $allowed_outputs;
    }

    /**
     * _executeModuleLogic function
     *
     * @param array $node
     * @return array
     */
    private function _executeModuleLogic(array $node, WorkflowRoamingData $roamingData): array
    {
        $outputs = ($node['outputs'] ?? []);
        if ($node['data']['id'] == 'if') {
            $useThenBranch = $this->_evaluateIFCondition($node, $roamingData);
            return $useThenBranch ? ['output_1' => $outputs['output_1']] : ['output_2' => $outputs['output_2']];
        }
        return $outputs;
    }

    private function _evaluateIFCondition($node, WorkflowRoamingData $roamingData): bool
    {
        $result = $this->WorkflowModel->__executeNode($node, $roamingData);
        return $result;
    }

    public function _walk($node_id, $path_type=null, array $path_list=[], WorkflowRoamingData $roamingData)
    {
        $this->cursor = $node_id;
        $node = $this->graph[$node_id];
        if ($node['data']['module_type'] != 'trigger' && $node['data']['module_type'] != 'logic') { // trigger and logic nodes should not be returned as they are "control" nodes
            yield ['node' => $node, 'path_type' => $path_type, 'path_list' => $path_list];
        }
        $allowedOutputs = $this->_evaluateOutputs($node, $roamingData);
        foreach ($allowedOutputs as $output_id => $outputs) {
            $path_type = $this->_getPathType($node_id, $path_type, $output_id);
            if (is_null($this->for_path) || $path_type == $this->for_path) {
                foreach ($outputs as $connections) {
                    foreach ($connections as $connection_id => $connection) {
                        $next_node_id = (int)$connection['node'];
                        $next_path_list = $path_list;
                        $next_path_list[] = sprintf('%s:%s:%s:%s', $node_id, $output_id, $connection_id, $next_node_id);
                        yield from $this->_walk($next_node_id, $path_type, $next_path_list, $roamingData);
                    }
                }
            }
        }
    }

    public function walk(WorkflowRoamingData $roamingData)
    {
        return $this->_walk($this->cursor, null, [], $roamingData);
    }
}

class WorkflowRoamingData
{
    private $workflow_user;
    private $data;

    public function __construct(array $workflow_user, array $data)
    {
        $this->workflow_user = $workflow_user;
        $this->data = $data;
    }

    public function getUser(): array
    {
        return $this->workflow_user;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): array
    {
        return $this->data = $data;
    }
}

class WorkflowGraphTool
{

    const GRAPH_BLOCKING_CONNECTION_NAME = 'output_1';
    const GRAPH_NON_BLOCKING_CONNECTION_NAME = 'output_2';

    /**
     * extractTriggersFromWorkflow Return the list of trigger names (or full node) that are specified in the workflow
     *
     * @param  array $workflow
     * @param  bool $fullNode
     * @return array
     */
    public static function extractTriggersFromWorkflow(array $graphData, bool $fullNode = false): array
    {
        $triggers = [];
        foreach ($graphData as $node) {
            if ($node['data']['module_type'] == 'trigger') {
                if (!empty($fullNode)) {
                    $triggers[] = $node;
                } else {
                    $triggers[] = $node['data']['id'];
                }
            }
        }
        return $triggers;
    }

    /**
     * triggerHasBlockingPath Return if the provided trigger has an edge leading to a blocking path
     * 
     * @param array $node
     * @returns bool
     */
    public static function triggerHasBlockingPath(array $node): bool
    {
        return !empty($node['outputs'][WorkflowGraphTool::GRAPH_BLOCKING_CONNECTION_NAME]['connections']);
    }

    /**
     * triggerHasBlockingPath Return if the provided trigger has an edge leading to a non-blocking path
     * 
     * @param array $node
     * @returns bool
     */
    public static function triggerHasNonBlockingPath(array $node): bool
    {
        return !empty($node['outputs'][WorkflowGraphTool::GRAPH_NON_BLOCKING_CONNECTION_NAME]['connections']);
    }

    /**
     * hisCyclic Return if the graph contains a cycle
     *
     * @param array $graphData
     * @param array $cycles Get a list of cycle
     * @return boolean
     */
    public static function isAcyclic(array $graphData, array &$cycles=[]): bool
    {
        $graphUtil = new GraphUtil($graphData);
        $result = $graphUtil->isCyclic();
        $isCyclic = $result[0];
        $cycles = $result[1];
        return !$isCyclic;
    }

    /**
     * Undocumented getNodeIdForTrigger
     *
     * @param array $graphData 
     * @param string $trigger_id
     * @return integer Return the ID of the node for the provided trigger and -1 if no nodes with this id was found.
     */
    public static function getNodeIdForTrigger(array $graphData, $trigger_id): int
    {
        $triggers = WorkflowGraphTool::extractTriggersFromWorkflow($graphData, true);
        foreach ($triggers as $node) {
            if ($node['data']['id'] == $trigger_id) {
                return $node['id'];
            }
        }
        return -1;
    }

    public static function getRoamingData(array $user, array $data)
    {
        return new WorkflowRoamingData($user, $data);
    }

    public static function getWalkerIterator(array $graphData, $WorkflowModel, $startNodeID, $path_type=null, WorkflowRoamingData $roamingData)
    {
        $graphWalker = new GraphWalker($graphData, $WorkflowModel, $startNodeID, $path_type);
        return $graphWalker->walk($roamingData);
    }
}
