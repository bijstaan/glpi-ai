<?php
/**
 * The Responses API translation.
 *
 * Pure unit checks: no kernel, no database, no configuration, no network. The
 * translator is the one place where a mistake is invisible at runtime — a
 * dropped reasoning item does not error, it just quietly costs the model the
 * thinking that led to the tool call it is looking at — so the round trip is
 * asserted rather than eyeballed.
 *
 * Usage, from anywhere:
 *   php tests/responses.php
 */
define('READ', 1);
$src = __DIR__ . '/../src/';
if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string { return $text; }
}
foreach ([
    'AiException','Usage','Tool','ToolCall','ToolResult','Message','Completion','Prompt',
    'Azure/Entra','Provider/Field','Provider/Provider','Provider/StreamingProvider','Provider/AbstractProvider',
    'Provider/Responses','Provider/OpenAi','Provider/AzureFoundry',
] as $f) {
    require $src . $f . '.php';
}
use GlpiPlugin\Glpiai\{Message, Prompt, Tool, ToolCall, ToolResult};
use GlpiPlugin\Glpiai\Provider\Responses;

$fail = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $fail;
    echo ($ok ? "  \033[32mPASS\033[0m  " : "  \033[31mFAIL\033[0m  ") . $name . ($detail !== '' ? " :: $detail" : '') . "\n";
    if (!$ok) { $fail[] = $name; }
}

echo "\nRequest body\n";
$prompt = Prompt::make('Why is printer 12 offline?', 'You are a helpdesk assistant.');
$prompt->tools = [new Tool('read_ticket', 'Read one ticket', ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]])];
$body = Responses::body($prompt, 'gpt-5.6', ['effort' => 'medium', 'summary' => true]);

check('system prompt becomes instructions, not an item', ($body['instructions'] ?? null) === 'You are a helpdesk assistant.');
check('max_tokens becomes max_output_tokens', isset($body['max_output_tokens']) && !isset($body['max_tokens']));
check('store is false', ($body['store'] ?? null) === false);
check('tool definition is flat, not nested under function', isset($body['tools'][0]['name']) && !isset($body['tools'][0]['function']));
check('reasoning effort and summary are sent', ($body['reasoning']['effort'] ?? '') === 'medium' && ($body['reasoning']['summary'] ?? '') === 'auto');
check('no reasoning.context on the first turn', !isset($body['reasoning']['context']));
check('input is a list of items', array_is_list($body['input']) && ($body['input'][0]['role'] ?? '') === 'user');

echo "\nResponse parsing\n";
$raw = [
    'status' => 'completed',
    'model'  => 'gpt-5.6-sol',
    'output' => [
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'ENCRYPTED'],
        ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_42', 'name' => 'read_ticket', 'arguments' => '{"id":12}'],
    ],
    'usage' => ['input_tokens' => 800, 'output_tokens' => 120, 'output_tokens_details' => ['reasoning_tokens' => 90]],
];
$completion = Responses::parse($raw, 'azure', 'gpt-5.6');
check('function_call becomes a ToolCall keyed on call_id', $completion->wantsTools() && $completion->tool_calls[0]->id === 'call_42');
check('arguments are decoded, not left as a JSON string', $completion->tool_calls[0]->arguments === ['id' => 12]);
check('usage reads the Responses field names', $completion->usage->input_tokens === 800 && $completion->usage->output_tokens === 120);
check('every output item is kept for replay, reasoning included', count($completion->vendor) === 2 && ($completion->vendor[0]['encrypted_content'] ?? '') === 'ENCRYPTED');

echo "\nThe round trip that matters\n";
$prompt->add(Message::toolCalls($completion->text, $completion->tool_calls, $completion->vendor));
$prompt->add(Message::toolResults([ToolResult::of($completion->tool_calls[0], ['status' => 'closed'])]));
$next = Responses::body($prompt, 'gpt-5.6', []);
$types = array_map(static fn(array $i): string => $i['type'] ?? ('role:' . ($i['role'] ?? '?')), $next['input']);

check('reasoning item is replayed verbatim', in_array('reasoning', $types, true)
    && ($next['input'][1]['encrypted_content'] ?? '') === 'ENCRYPTED', implode(', ', $types));
check('the call is replayed before its output', array_search('function_call', $types, true) < array_search('function_call_output', $types, true));
check('the output correlates on call_id', ($next['input'][3]['call_id'] ?? '') === 'call_42');
check('reasoning.context switches to all_turns once there is history', ($next['reasoning']['context'] ?? '') === 'all_turns');

echo "\nTruncation and streaming\n";
$cut = Responses::parse(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => []], 'azure', 'gpt-5.6');
check('an incomplete response reports as truncated', $cut->wasTruncated());

$final = [];
$seen  = [];
$sink  = static function (string $ch, string $t) use (&$seen): void { $seen[$ch] = ($seen[$ch] ?? '') . $t; };
foreach ([
    ['type' => 'response.created', 'response' => ['status' => 'in_progress']],
    ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Checking '],
    ['type' => 'response.output_text.delta', 'delta' => 'Printer 12 '],
    ['type' => 'response.output_text.delta', 'delta' => 'is offline.'],
    ['type' => 'response.completed', 'response' => $raw],
] as $frame) {
    Responses::frame($frame, $final, $sink);
}
check('text deltas stream on the text channel', ($seen['text'] ?? '') === 'Printer 12 is offline.');
check('reasoning streams on the thinking channel, never as text', ($seen['thinking'] ?? '') === 'Checking ');
check('the terminal frame supplies the object, not response.created', ($final['status'] ?? '') === 'completed');

echo "\nWhich API each adapter defaults to\n";
$declared = static function (string $class, string $field): string {
    foreach ($class::fields() as $f) {
        if ($f->name === $field) { return (string) $f->default; }
    }
    return '(absent)';
};
$uses = static function (object $provider): bool {
    $m = new ReflectionMethod($provider, 'usesResponses');
    $m->setAccessible(true);
    return (bool) $m->invoke($provider);
};

check('OpenAI leaves the choice to the endpoint by default', $declared(GlpiPlugin\Glpiai\Provider\OpenAi::class, 'api_style') === 'auto');
// The regression that would be invisible: an Azure resource configured before
// this path existed must not move endpoint on upgrade.
check('Azure keeps its configured chat style by default',
    $declared(GlpiPlugin\Glpiai\Provider\AzureFoundry::class, 'api_style') === 'azure_openai');
check('OpenAI with no custom endpoint reports Responses', $uses(new GlpiPlugin\Glpiai\Provider\OpenAi([])));
// The upgrade case: a gateway configured before api_style existed has no value
// stored, and must not be moved onto an API it does not implement.
check('a gateway endpoint is left on chat without being asked',
    !$uses(new GlpiPlugin\Glpiai\Provider\OpenAi(['base_url' => 'http://192.168.86.64:11434/v1'])));
check('an explicit choice still wins over the endpoint',
    $uses(new GlpiPlugin\Glpiai\Provider\OpenAi(['base_url' => 'https://gateway.internal/v1', 'api_style' => 'responses'])));
check('an unconfigured Azure adapter does not', !$uses(new GlpiPlugin\Glpiai\Provider\AzureFoundry([])));
check('a gateway pinned to chat stays on chat',
    !$uses(new GlpiPlugin\Glpiai\Provider\OpenAi(['api_style' => 'chat'])));
check('Azure switched to responses reports so',
    $uses(new GlpiPlugin\Glpiai\Provider\AzureFoundry(['api_style' => 'responses'])));

echo "\n" . ($fail === [] ? "\033[32mAll checks passed\033[0m\n" : "\033[31m" . count($fail) . " failed\033[0m\n");
exit($fail === [] ? 0 : 1);
