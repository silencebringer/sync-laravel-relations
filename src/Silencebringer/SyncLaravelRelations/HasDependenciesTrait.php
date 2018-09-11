<?php

namespace App\Traits;

trait HasDependenciesTrait
{
    protected $higherLevelDependencies;
    protected $lowerLevelDependencies;

    protected $filterAvailableActions = true;

    protected $dependenciesScopes = [];

    /**
     * Identifiable Name
     *
     * @return string an identifying name for the model
     */
    public function identifiableName()
    {
        return $this->getKey();
    }

    public function setDependenciesScopes($dependenciesScopes)
    {
        $this->dependenciesScopes = $dependenciesScopes;
    }

    public function dependencyOptions()
    {
        if ($this->dependencyOptionName && !$this->doNotLoadOptions) {
            $name = $this->dependencyOptionName;

            return $this->select()
                ->orderBy($this->dependencyOptionName, 'asc')
                ->get()
                ->transform(function ($item) use ($name) {
                    return [
                        'id'   => $item['id'],
                        'name' => $item->$name,
                    ];
                });
        }

        return null;
    }

    public function lowerLevelDependencies($initial = true)
    {
        if (!$this->lowerLevelDependencies) {
            $dependencies = collect([]);

            $deep = 0;

            if ($this->useEagerLoading() && $initial) {
                $this->load($this->getLowerLevelRelationsList());
            }

            foreach ($this->getLowerLevelRelations() as $lowerLevelRelation) {
                $subDeep = 1;

                $relation = $this->$lowerLevelRelation();

                $dependentModel = $this->$lowerLevelRelation;

                if ($dependentModel) {
                    $subDeep = 1;

                    $dependencyData = [
                        'model'   => get_class($dependentModel),
                        'id'      => $dependentModel->getKey(),
                        'name'    => $dependentModel->identifiableName(),
                        'field'   => $relation->getForeignKey(),
                        //'label'   => $dependentModel->getDependencyName(),
                        'label'   => $this->getDependencyName($dependentModel),
                        'deep'    => $subDeep,
                        'actions' => $this->getAvailableActionsForLowerLevelDependentModel($dependentModel),
                        'options' => $dependentModel->dependencyOptions()
                    ];

                    if ($this->checkDependenciesRecursively() && method_exists($dependentModel, 'lowerLevelDependencies')) {
                        $relatedModelDependencies = $dependentModel->lowerLevelDependencies(false);

                        if ($relatedModelDependencies['dependencies']->count()) {
                            $dependencyData['dependencies'] = $relatedModelDependencies['dependencies'];
                            $subDeep = max($subDeep, $relatedModelDependencies['deep'] + 1);
                            $dependencyData['deep'] = $subDeep;
                        }
                    }

                    $dependencies->push($dependencyData);
                }

                $deep = max($deep, $subDeep);
            }

            $this->lowerLevelDependencies = !$initial ? compact('dependencies', 'deep') : $dependencies;
        }

        return $this->lowerLevelDependencies;
    }

    public function higherLevelDependencies($initial = true)
    {
        if (!$this->higherLevelDependencies) {
            $dependencies = collect([]);

            $deep = 0;

            if ($this->useEagerLoading() && $initial) {
                $this->load($this->getHigherLevelRelationsList());
            }

            foreach ($this->getHigherLevelRelations() as $higherLevelRelation) {
                $subDeep = 1;

                $relation = $this->$higherLevelRelation();

                if (method_exists(get_class($relation->getRelated()), 'scopeHigherDependenciesAdditionalFilters')) {
                    $relation->higherDependenciesAdditionalFilters();
                }

                $higherLevelDependencies = $this->$higherLevelRelation;

                if ($higherLevelDependencies->count()) {
                    $options = $this->dependencyOptions();

                    foreach ($higherLevelDependencies as $dependentModel) {
                        $subDeep = 1;

                        $dependencyData = [
                            'model'   => get_class($dependentModel),
                            'id'      => $dependentModel->getKey(),
                            'name'    => $dependentModel->identifiableName(),
                            'field'   => $relation->getForeignKeyName(),
                            //'label'   => $dependentModel->getDependencyName(),
                            'label'   => $this->getDependencyName($dependentModel),
                            'deep'    => $subDeep,
                            'actions' => $this->getAvailableActionsForHigherLevelDependentModel($dependentModel),
                        ];

                        if ($initial) {
                            $dependencyData['options'] = $options;
                        }

                        if ($this->checkDependenciesRecursively() && method_exists($dependentModel, 'higherLevelDependencies')) {
                            $relatedModelDependencies = $dependentModel->higherLevelDependencies(false);

                            if ($relatedModelDependencies['dependencies']->count()) {
                                $dependencyData['dependencies'] = $relatedModelDependencies['dependencies'];
                                $subDeep = max($subDeep, $relatedModelDependencies['deep'] + 1);
                                $dependencyData['deep'] = $subDeep;
                            }
                        }

                        $dependencies->push($dependencyData);
                    }
                }

                $deep = max($deep, $subDeep);
            }

            $this->higherLevelDependencies = !$initial ? compact('dependencies', 'deep') : $dependencies;
        }

        return $this->higherLevelDependencies;
    }

    public function getHigherLevelRelationsList()
    {
        $relationsList = $this->getHigherLevelRelations();

        foreach ($this->getHigherLevelRelations() as $higherLevelRelation) {
            if ($this->checkDependenciesRecursively()
                && method_exists($this->$higherLevelRelation()->getRelated(), 'getHigherLevelRelationsList')
                && count($this->$higherLevelRelation()->getRelated()->getHigherLevelRelationsList())
            ) {
                $relationsList = array_merge(
                    $relationsList,
                    array_map(
                        function ($relation) use ($higherLevelRelation) {
                            return $higherLevelRelation . '.' . $relation;
                        },
                        $this->$higherLevelRelation()->getRelated()->getHigherLevelRelationsList()
                    )
                );
            }
        }

        return $relationsList;
    }

    public function getLowerLevelRelationsList()
    {
        $relationsList = $this->getLowerLevelRelations();

        foreach ($this->getLowerLevelRelations() as $lowerLevelRelation) {
            if ($this->checkDependenciesRecursively()
                && method_exists($this->$lowerLevelRelation()->getRelated(), 'getLowerLevelRelationsList')
                && count($this->$lowerLevelRelation()->getRelated()->getLowerLevelRelationsList())
            ) {
                $relationsList = array_merge(
                    $relationsList,
                    array_map(
                        function ($relation) use ($lowerLevelRelation) {
                            return $lowerLevelRelation . '.' . $relation;
                        },
                        $this->$lowerLevelRelation()->getRelated()->getLowerLevelRelationsList()
                    )
                );
            }
        }

        foreach ($relationsList as $key => $relation) {
            if (array_key_exists($relation, $this->dependenciesScopes) && is_callable($this->dependenciesScopes[$relation])) {
                $relationsList[$relation] = $this->dependenciesScopes[$relation];

                unset($relationsList[$key]);
            }
        }

        return $relationsList;
    }

    public function getHigherLevelDependenciesDeep()
    {
        $maxDeep = 0;

        foreach ($this->higherLevelDependencies() as $dependency) {
            $maxDeep = max($maxDeep, $dependency['deep']);
        }

        return $maxDeep;
    }

    public function getHigherLevelRelations()
    {
        return (array) $this->higherLevelRelations;
    }

    public function getLowerLevelRelations()
    {
        return (array) $this->lowerLevelRelations;
    }

    public function setHigherLevelRelations($higherLevelDependencies)
    {
        $this->higherLevelRelations = $higherLevelDependencies;
    }

    protected function modelUseSoftDeletes($model)
    {
        return isset($this->forceDeleting);
    }

    public function getAvailableActionsForHigherLevelDependentModel($dependentModel = null)
    {
        if ($dependentModel) {
            $actions = method_exists($dependentModel, 'getAvailableActionsForHigherLevelDependentModel')
                ? $dependentModel->getAvailableActionsForHigherLevelDependentModel()
                : [];

            if ($this->filterAvailableActions) {
                return array_values(
                    array_intersect($actions, $this->getAvailableActionsForHigherLevelDependentModel())
                );
            }
        }

        return $this->availableActionsForHigherLevelDependentModel ?? [];
    }

    public function getAvailableActionsForLowerLevelDependentModel($dependentModel = null)
    {
        if ($dependentModel) {
            $actions = method_exists($dependentModel, 'getAvailableActionsForLowerLevelDependentModel')
                ? $dependentModel->getAvailableActionsForLowerLevelDependentModel()
                : [];

            if ($this->filterAvailableActions) {
                return array_values(
                    array_intersect($actions, $this->getAvailableActionsForLowerLevelDependentModel())
                );
            }
        }

        return $this->availableActionsForLowerLevelDependentModel ?? [];
    }

    public function getDependencyName($dependentModel = null)
    {
        if ($dependentModel) {
            if (in_array(HasDependenciesTrait::class, class_uses($dependentModel))) {
                return $dependentModel->getDependencyName();
            } else {
                return substr(strrchr(get_class($dependentModel), "\\"), 1);
            }
        }

        return $this->dependencyName ?? substr(strrchr(get_class($this), "\\"), 1);
    }

    public function doNotFilterDependenciesActions()
    {
        $this->filterAvailableActions = false;
    }

    /**
     * Check dependencies recursively.
     *
     * @return bool
     */
    protected function checkDependenciesRecursively()
    {
        return $this->disableDeepDependenciesCheck ? !$this->disableDeepDependenciesCheck : true;
    }

    protected function useEagerLoading()
    {
        return $this->disableDependenciesEagerLoading ? !$this->disableDependenciesEagerLoading : true;
    }
}