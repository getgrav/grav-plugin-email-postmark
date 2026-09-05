<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\EmailPostmark\Provider\PostmarkProvider;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class EmailPostmarkPlugin
 * @package Grav\Plugin
 */
class EmailPostmarkPlugin extends Plugin
{
    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onEmailEngines'       => ['onEmailEngines', 0],
            'onEmailTransportDsn'  => ['onEmailTransportDsn', 0],
            'onEmailProviders'     => ['onEmailProviders', 0],
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onEmailEngines(Event $e)
    {
        $engines = $e['engines'];
        $engines->postmark = 'Postmark';
    }

    public function onEmailTransportDsn(Event $e)
    {
        $engine = $e['engine'];
        if ($engine === 'postmark') {
            $options = $this->config->get('plugins.email-postmark');
            $transport = $options['transport'] ?? 'api';
            $dsn = "postmark+{$transport}://";
            $dsn .= urlencode($options['api_token'] ?? '');
            $dsn .= "@default";

            // The stream messages leave on. Left off the DSN when it is empty
            // or the default, so a store that never touched the field keeps the
            // exact transport it had.
            $stream = trim((string)($options['message_stream'] ?? ''));
            if ($stream !== '' && $stream !== 'outbound') {
                $dsn .= '?message_stream=' . urlencode($stream);
            }

            $e['dsn'] = $dsn;
            $e->stopPropagation();
        }
    }

    /**
     * Everything Postmark knows about itself, handed to whoever asked.
     *
     * How its webhooks are checked and read, how one is created from the token
     * already pasted into this plugin, and what a sending domain's DNS has to
     * say. All of it used to live in whichever add-on happened to need the
     * answer first; it lives here now, and the Email plugin owns the contract.
     *
     * Nothing here does any work. The value object is built and handed over —
     * this listener runs on every admin screen that draws a settings block, so
     * a network call in it would be a settings screen that hangs.
     */
    public function onEmailProviders(Event $e)
    {
        // A site whose Email plugin predates the contract fires no such event,
        // so reaching this at all means the class is there. Checked anyway,
        // because a plugin half-updated on disk is a fatal error otherwise and
        // this is a listener rather than a code path anybody chose.
        if (!class_exists(\Grav\Plugin\Email\Providers\ProviderRegistry::class)) {
            return;
        }

        $providers = $e['providers'];

        if (\is_object($providers) && method_exists($providers, 'add')) {
            $providers->add(new PostmarkProvider((array)$this->config->get('plugins.email-postmark', [])));
        }
    }
}
