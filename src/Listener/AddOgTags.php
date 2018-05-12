<?php

namespace radixi0\sharing\Listener;

use Flarum\Api\Controller\ListDiscussionsController;
use Flarum\Api\Controller\ShowDiscussionController;
use Flarum\Api\Controller\ShowUserController;
use Flarum\Event\ConfigureWebApp;
use Flarum\Event\PrepareApiData;
use Flarum\Forum\UrlGenerator;
use Flarum\Http\Controller\ClientView;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;

class AddOgTags
{
    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @var UrlGenerator
     */
    protected $urlGenerator;

    /**
     * @var ClientView
     */
    protected $clientView;

    /**
     * @var ogData
     */
    protected $ogData;

    public function __construct(SettingsRepositoryInterface $settings, UrlGenerator $urlGenerator)
    {
        $this->settings = $settings;
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * @param Dispatcher $events
     */
    public function subscribe(Dispatcher $events)
    {
        $events->listen(ConfigureWebApp::class, [$this, 'getClientView']);
        $events->listen(PrepareApiData::class, [$this, 'addMetaTags']);
    }

    /**
     * @param ConfigureClientView $event
     */
    public function getClientView(ConfigureWebApp $event)
    {
        if ($event->isForum()) {
            $this->clientView = $event->view;

            $data = [];
            $data['url'] = $this->urlGenerator->toBase();
            $data['title'] = $this->plainText($this->settings->get('welcome_title'), 80);
            $data['description'] = html_entity_decode($this->plainText($this->settings->get('forum_description'), 150));

            $this->addOpenGraph([
                'type' => 'article',
                'site_name' => $this->settings->get('forum_title'),
                'image' => ''
            ]);
            $this->addOpenGraph($data);
            $this->addTwitterCard([
                'card' => 'summary',
                'image' => ''
            ]);
            $this->addTwitterCard($data);
            $this->addFacebookApi();
            //Add canonical for non slug url
            $uri = $event->request->getUri();
            $path = strtolower($uri->getPath());
            if (substr( $path, 0, 3 ) === "/d/") {
                //$event->view->description = 'testing';
                /*$discussionTitle = $event->view->title;
                $discussionID = $event->view->document->data->id;
                $slug = $event->view->document->data->attributes->slug;
                $firstPost = $event->view->document->included[0]->attributes->contentHtml;*/
                $re = '/\/d\/\d+-/m';
                preg_match_all($re, $path, $matches, PREG_SET_ORDER, 0); // is this a full URL with slug?
                // Print the entire match result
                //var_dump($matches);
                if (count ($matches) === 0) { // URL with NO slug
                    $fullURL = $this->urlGenerator->toRoute('discussion', ['id' => $this->ogData->id . '-' . $this->ogData->slug]);
                    $this->clientView->addHeadString('<link rel="canonical" href="' . $fullURL . '" />','canonical');
                }
            }
        }
    }

    /**
     * @param PrepareApiData $event
     */
    public function addMetaTags(PrepareApiData $event)
    {
        if ($this->clientView) {

            $data = [];
            $data['url'] = $this->urlGenerator->toRoute('discussion', ['id' => $this->ogData->id . '-' . $this->ogData->slug]);
            $data['title'] = $this->plainText($this->ogData->title, 80);
            $post_id = $event->request->getQueryParams()['page']['near'];
            $pattern = '/!\[(.*)\]\s?\((.*)(.png|.gif|.jpg|.jpeg)(.*)\)/';

            if ($post_id === null) {

                $data['description'] = $this->ogData->startPost ? $this->plainText(preg_replace($pattern, '', $this->ogData->startPost->content), 150) : '';

                if (preg_match($pattern, $this->ogData->startPost->content, $matches)) {
                    $data['image'] = $matches[2] . $matches[3];
                }

            } else {

                $post = array_key_exists((int)$post_id - 1, $this->ogData->posts) ? $this->ogData->posts[(int)$post_id - 1] : null;
                $data['url'] .= '/' . $post_id;
                if ($post) {
                    $data['description'] = $this->plainText(preg_replace($pattern, '', $post->content), 150);
                } else {
                    $data['description'] = $this->ogData->startPost ? $this->plainText(preg_replace($pattern, '', $this->ogData->startPost->content), 150) : '';
                }
            }

            // if no images found
            if ($data['image'] == '') {
                $data['image'] = $this->urlGenerator->toBase() . '/assets/' . $this->settings->get('logo_path');
            }
            //https://stackoverflow.com/questions/659025/how-to-remove-non-alphanumeric-characters/17151182#17151182
            $data['description'] = preg_replace("/[^[:alnum:][:space:]]/ui", '',preg_quote($data['description'], '/') );

            $this->addOpenGraph($data);
            $this->addTwitterCard($data);

            $this->clientView->description = $data['description'];

        } else {
            $this->ogData = $event->data;
        }
    }

    /**
     * @param array $data
     */
    public function addOpenGraph(array $data = [])
    {
        foreach ($data as $key => $value) {
            $this->clientView->addHeadString('<meta property="og:' . $key . '" content="' . $value . '"/>', 'og_' . $key . '');
        }
    }

    /**
     * @param array $data
     */
    public function addTwitterCard(array $data = [])
    {
        foreach ($data as $key => $value) {
            $this->clientView->addHeadString('<meta property="twitter:' . $key . '" content="' . $value . '"/>', 'twitter_' . $key . '');
        }
    }

    /**
     * @return string
     */
    public function addFacebookApi()
    {
        if ($this->clientView) {
            $fbappID = $this->settings->get('radixio.sharing.facebookAppId');
            if (strlen($fbappID) > 0) {
                $this->clientView->addHeadString(str_replace('{0}',
                    $fbappID,
                                      '<script>
                                                window.fbAsyncInit = function() {
                                                    FB.init({
                                                    appId            : \'{0}\',
                                                    autoLogAppEvents : true,
                                                    xfbml            : true,
                                                    version          : \'v2.9\'
                                                    });
                                                    FB.AppEvents.logPageView();
                                                };

                                                (function(d, s, id){
                                                    var js, fjs = d.getElementsByTagName(s)[0];
                                                    if (d.getElementById(id)) {return;}
                                                    js = d.createElement(s); js.id = id;
                                                    js.src = "//connect.facebook.net/en_US/sdk.js";
                                                    fjs.parentNode.insertBefore(js, fjs);
                                                }(document, \'script\', \'facebook-jssdk\'));
                                            </script>'));
            }
        }
    }

    /**
     * @param string $text
     * @param int|null $length
     * @return string
     */
    protected function plainText($text, $length = null)
    {
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_DISALLOWED | ENT_SUBSTITUTE, 'UTF-8');
        if ($length !== null) {
            $text = mb_strlen($text, 'UTF-8') > $length ? mb_substr($text, 0, $length, 'UTF-8') . '...' : $text;
        }

        return $text;
    }
}