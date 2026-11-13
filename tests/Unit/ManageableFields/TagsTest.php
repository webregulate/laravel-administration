<?php

namespace WebRegulate\LaravelAdministration\Tests\Unit\ManageableFields;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\Tags;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\Text;

class TagsTest extends TestCase
{
    public function test_comma_separated_duplicates_are_removed_without_changing_other_fields(): void
    {
        $request = Request::create('/', 'POST');

        $tags = Tags::make(null, 'notes');
        $this->assertFalse($tags->options['storeAsJson']);
        $this->assertSame(
            't-hsl_2024,other',
            $tags->applySubmittedValueFinal($request, 't-hsl_2024, t-hsl_2024,other,t-hsl_2024')
        );
        $this->assertSame('a,b', $tags->applySubmittedValueFinal($request, 'a,, b ,a,'));
        $this->assertSame('', $tags->applySubmittedValueFinal($request, '   '));
        $this->assertNull($tags->applySubmittedValueFinal($request, null));
        $this->assertSame(
            ['t-hsl_2024', 'other'],
            $tags->applySubmittedValueFinal($request, ['t-hsl_2024', 't-hsl_2024', 'other'])
        );

        // A string that merely looks like JSON stays a comma-separated string.
        $this->assertSame('["a","a"]', Tags::normaliseSubmittedTags('["a","a"]'));

        $text = Text::make(null, 'notes');
        $this->assertSame(
            't-hsl_2024,t-hsl_2024',
            $text->applySubmittedValueFinal($request, 't-hsl_2024,t-hsl_2024')
        );
    }

    public function test_json_lists_are_deduped_and_other_json_is_left_alone(): void
    {
        $this->assertSame('["a","b"]', Tags::normaliseSubmittedTags('["a","a","b"]', true));
        $this->assertSame('["b","a"]', Tags::normaliseSubmittedTags('["b","a","b"]', true));
        $this->assertSame('[1,2]', Tags::normaliseSubmittedTags('[1,1,2]', true));
        $this->assertSame('["a", "b"]', Tags::normaliseSubmittedTags('["a", "b"]', true));
        $this->assertSame('{"a":"a"}', Tags::normaliseSubmittedTags('{"a":"a"}', true));
        $this->assertSame(['a', 'b'], Tags::normaliseSubmittedTags(['a', 'a', 'b']));
        $this->assertSame([1, 2], Tags::normaliseSubmittedTags([1, 1, 2]));
        $this->assertSame(['a' => 'a'], Tags::normaliseSubmittedTags(['a' => 'a']));
        $this->assertSame([['a'], ['a']], Tags::normaliseSubmittedTags([['a'], ['a']]));
    }
}
