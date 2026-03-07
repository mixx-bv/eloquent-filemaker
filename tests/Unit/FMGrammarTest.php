<?php

namespace Tests\Unit;

use GearboxSolutions\EloquentFileMaker\Database\Query\FMBaseBuilder;
use GearboxSolutions\EloquentFileMaker\Database\Query\Grammars\FMGrammar;
use GearboxSolutions\EloquentFileMaker\Services\FileMakerConnection;

// FMGrammar imported for type-hint in grammar() return type
use Tests\TestCase;

class FMGrammarTest extends TestCase
{
    protected FileMakerConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = app(FileMakerConnection::class);
    }

    protected function builder(): FMBaseBuilder
    {
        return new FMBaseBuilder($this->connection);
    }

    protected function grammar(): FMGrammar
    {
        return $this->connection->getQueryGrammar();
    }

    // -------------------------------------------------------
    // Basic (non-nested) sanity checks
    // -------------------------------------------------------

    public function test_simple_where_produces_single_find_request(): void
    {
        $query = $this->builder()->where('mark', 'jef');

        $find = $this->grammar()->compileSelect($query)['find'];

        $this->assertCount(1, $find);
        $this->assertEquals('==jef', $find[0]['mark']);
    }

    public function test_two_and_wheres_merge_into_one_find_request(): void
    {
        $query = $this->builder()->where('mark', 'jef')->where('city', 'gent');

        $find = $this->grammar()->compileSelect($query)['find'];

        $this->assertCount(1, $find);
        $this->assertEquals('==jef', $find[0]['mark']);
        $this->assertEquals('==gent', $find[0]['city']);
    }

    public function test_or_where_produces_two_find_requests(): void
    {
        $query = $this->builder()->where('mark', 'jef')->orWhere('city', 'gent');

        $find = $this->grammar()->compileSelect($query)['find'];

        $this->assertCount(2, $find);
        $this->assertEquals('==jef', $find[0]['mark']);
        $this->assertEquals('==gent', $find[1]['city']);
    }

    // -------------------------------------------------------
    // OR-nested: orWhere(fn) appends independent requests
    // -------------------------------------------------------

    /**
     * ->where('mark', 'jef')
     * ->orWhere(fn($q) => $q->where('jan', 'test')->where('gerald', 'jef'))
     *
     * Expected FM query:
     *   [ {mark: ==jef}, {jan: ==test, gerald: ==jef} ]
     */
    public function test_or_nested_where_appends_independent_find_request(): void
    {
        $query = $this->builder()
            ->where('mark', 'jef')
            ->orWhere(fn ($q) => $q->where('jan', 'test')->where('gerald', 'jef'));

        $find = $this->grammar()->compileSelect($query)['find'];

        $this->assertCount(2, $find);
        $this->assertEquals(['mark' => '==jef'], $find[0]);
        $this->assertEquals(['jan' => '==test', 'gerald' => '==jef'], $find[1]);
    }

    /**
     * ->where('mark', 'jef')
     * ->orWhere(fn($q) => $q->where('jan', 'test')->where('gerald', 'jef'))
     * ->where('city', 'gent')
     * ->orWhere('qsdf', 'qsdf')
     *
     * SQL: mark='jef' OR (jan='test' AND gerald='jef' AND city='gent') OR qsdf='qsdf'
     * Note: the ->where('city','gent') after the orWhere ANDs onto the LAST request.
     *
     * Expected FM query:
     *   [ {mark: ==jef}, {jan: ==test, gerald: ==jef, city: ==gent}, {qsdf: ==qsdf} ]
     */
    public function test_or_nested_then_and_then_or_produces_correct_requests(): void
    {
        $query = $this->builder()
            ->where('mark', 'jef')
            ->orWhere(fn ($q) => $q->where('jan', 'test')->where('gerald', 'jef'))
            ->where('city', 'gent')
            ->orWhere('qsdf', 'qsdf');

        $find = $this->grammar()->compileSelect($query)['find'];

        $this->assertCount(3, $find);
        $this->assertEquals(['mark' => '==jef'], $find[0]);
        $this->assertEquals(['jan' => '==test', 'gerald' => '==jef', 'city' => '==gent'], $find[1]);
        $this->assertEquals(['qsdf' => '==qsdf'], $find[2]);
    }

    // -------------------------------------------------------
    // AND-nested: where(fn) cross-products with existing requests
    // -------------------------------------------------------

    /**
     * ->where('mark', 'jef')
     * ->where(fn($q) => $q->where('jan', 'test')->orWhere('gerald', 'jef'))
     *
     * SQL: mark='jef' AND (jan='test' OR gerald='jef')
     * Expected FM query:
     *   [ {mark: ==jef, jan: ==test}, {mark: ==jef, gerald: ==jef} ]
     */
    public function test_and_nested_where_cross_products_with_existing_requests(): void
    {
        $query = $this->builder()
            ->where('mark', 'jef')
            ->where(fn ($q) => $q->where('jan', 'test')->orWhere('gerald', 'jef'));

        $find = $this->grammar()->compileSelect($query)['find'];

        $this->assertCount(2, $find);
        $this->assertEquals(['mark' => '==jef', 'jan' => '==test'], $find[0]);
        $this->assertEquals(['mark' => '==jef', 'gerald' => '==jef'], $find[1]);
    }

    /**
     * ->where(fn($q) => $q->where('a', '1')->orWhere('b', '2'))
     * ->where(fn($q) => $q->where('c', '3')->orWhere('d', '4'))
     *
     * SQL: (a=1 OR b=2) AND (c=3 OR d=4)
     * Expected FM query:
     *   [ {a:==1, c:==3}, {a:==1, d:==4}, {b:==2, c:==3}, {b:==2, d:==4} ]
     */
    public function test_two_and_nested_groups_produce_full_cross_product(): void
    {
        $query = $this->builder()
            ->where(fn ($q) => $q->where('a', '1')->orWhere('b', '2'))
            ->where(fn ($q) => $q->where('c', '3')->orWhere('d', '4'));

        $find = $this->grammar()->compileSelect($query)['find'];

        $this->assertCount(4, $find);
        $this->assertEquals(['a' => '==1', 'c' => '==3'], $find[0]);
        $this->assertEquals(['a' => '==1', 'd' => '==4'], $find[1]);
        $this->assertEquals(['b' => '==2', 'c' => '==3'], $find[2]);
        $this->assertEquals(['b' => '==2', 'd' => '==4'], $find[3]);
    }
}
