<?php

namespace GearboxSolutions\EloquentFileMaker\Database\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;

class FMGrammar extends Grammar
{

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return 'n/j/Y g:i:s A';
    }

    public function substituteBindingsIntoRawSql($sql, $bindings)
    {
        return $sql;
    }

    public function compileSelect(Builder $query): array
    {
        return [
            'find' => $this->compileWhereArray($query->wheres ?? [], false),
            'omit' => [],
        ];
    }

    protected function compileWhereArray(array $wheres, bool $isNested): array
    {
        $requests = [[]];
        foreach ($wheres as $where) {
            $requests = $this->applyWhere($requests, $where, $isNested);
        }

        return $requests;
    }

    protected function applyWhere(array $requests, array $where, bool $isNested): array
    {
        return match ($where['type']) {
            'Basic'   => $this->applyBasicWhere($requests, $where),
            'Nested'  => $this->applyNestedWhere($requests, $where,$isNested),
            'In'      => $this->applyInWhere($requests, $where),
            'between' => $this->applyBetweenWhere($requests, $where),
            'Null'    => $this->applyNullWhere($requests, $where),
            'NotNull' => $this->applyNotNullWhere($requests, $where),
            default   => throw new \RuntimeException("Unsupported where type: {$where['type']}"),
        };
    }

    protected function applyBasicWhere(array $requests, array $where): array
    {
        $currentRequest = last($requests);
        if ($where['boolean'] === 'or') {
            $requests[] = [
                $where['column'] => $this->applyOperator($where)
            ];
        } else if ($where['boolean'] === 'and') {
            $currentRequest[$where['column']] = $this->applyOperator($where);
            array_pop($requests);
            $requests[] = $currentRequest;
        }
        return $requests;
    }
    protected function applyOperator($where):string
    {
        $operator = $where['operator'];
        $value = $where['value'];
        if($operator === '='){
            return '==' . $value;
        } elseif($operator === 'like') {
            return str_replace('%', '*', $value);
        } elseif($operator === '!=') {
            return '<>'.$value;
        } else {
            return $operator.$value;
        }
    }

    protected function applyNestedWhere(array $requests, array $where): array
    {
        $nested = $where['query'];
        $nestedRequests = $this->compileWhereArray($nested->wheres, true);

        if ($where['boolean'] === 'or') {
            // OR-nested: the nested group produces independent find requests that are
            // appended to the existing ones (FileMaker OR = separate array entries).
            // Each nested request stands alone — it does NOT inherit prior AND conditions.
            foreach ($nestedRequests as $nestedRequest) {
                $requests[] = $nestedRequest;
            }
        } else {
            // AND-nested: cross-product — every existing request is merged with every
            // nested request so that the AND constraint applies to all branches.
            $crossed = [];
            foreach ($requests as $existingRequest) {
                foreach ($nestedRequests as $nestedRequest) {
                    $crossed[] = array_merge($existingRequest, $nestedRequest);
                }
            }
            $requests = $crossed;
        }

        return $requests;
    }

    protected function applyInWhere(array $requests, array $where): array
    {
        $expanded = [];
        foreach ($requests as $request) {
            foreach ($where['values'] as $value) {
                $copy = $request;
                $copy[$where['column']] = $value;
                $expanded[] = $copy;
            }
        }
        return $expanded;
    }

    protected function applyBetweenWhere(array $requests, array $where): array
    {
        [$min, $max] = $where['values'];
        foreach ($requests as &$request) {
            $request[$where['column']] = "{$min}...{$max}";
        }
        return $requests;
    }

    protected function applyNullWhere(array $requests, array $where): array
    {
        foreach ($requests as &$request) {
            $request[$where['column']] = '=';
        }
        return $requests;
    }

    protected function applyNotNullWhere(array $requests, array $where): array
    {
        foreach ($requests as &$request) {
            $request[$where['column']] = '≠';
        }
        return $requests;
    }
}
