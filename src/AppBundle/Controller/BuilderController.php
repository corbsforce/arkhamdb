<?php
namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use AppBundle\Entity\Deck;
use AppBundle\Entity\Deckslot;
use AppBundle\Entity\Card;
use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Entity\Deckchange;
use AppBundle\Helper\DeckValidationHelper;

class BuilderController extends Controller
{

	public function buildformAction (Request $request)
	{
		$response = new Response();
		$response->setPublic();
		$response->setMaxAge($this->container->getParameter('cache_expiration'));

		/* @var $em \Doctrine\ORM\EntityManager */
		$em = $this->getDoctrine()->getManager();

		$type = $em->getRepository('AppBundle:Type')->findOneBy(['code' => 'investigator']);
		$investigators = $em->getRepository('AppBundle:Card')->findBy(['type' => $type]);
		//$agenda = $em->getRepository('AppBundle:Type')->findOneBy(['code' => 'agenda']);
		//$agendas = $em->getRepository('AppBundle:Card')->findBy(['type' => $agenda]);

		return $this->render('AppBundle:Builder:initbuild.html.twig', [
				'pagetitle' => "New deck",
				'investigators' => $investigators
		], $response);
    }

    public function initbuildAction (Request $request)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->getDoctrine()->getManager();

        $investigator_code = $request->request->get('investigator');

        if(!$investigator_code)
        {
        	$this->get('session')->getFlashBag()->set('error', "An investigator is required.");
        	return $this->redirect($this->generateUrl('deck_buildform'));
        }

        $investigator = $em->getRepository('AppBundle:Card')->findOneBy(array("code" => $investigator_code));
        if(!$investigator)
        {
        	$this->get('session')->getFlashBag()->set('error', "An investigator is required.");
        	return $this->redirect($this->generateUrl('deck_buildform'));
        }
        $tags = [];
				
				$cards_to_add = [];
				// parse deck requirements and pre-fill deck with needed cards
				if ($investigator->getDeckRequirements()){
					$deck_requirements = $this->get('DeckValidationHelper')->parseReqString($investigator->getDeckRequirements());
					if ($deck_requirements['card']){
						foreach($deck_requirements['card'] as $card_code){
							if ($card_code){
								$card_to_add = $em->getRepository('AppBundle:Card')->findOneBy(array("code" => $card_code));
								if ($card_to_add){
									$cards_to_add[] = $card_to_add;
								}
							}
						}
					}
				}
				
				$pack = $investigator->getPack();
				$name = sprintf("New deck: %s", $investigator->getName());

        $deck = new Deck();
        $deck->setDescriptionMd("");
        $deck->setCharacter($investigator);
        $deck->setLastPack($pack);
        $deck->setName($name);
        $deck->setProblem('too_few_cards');
        $deck->setTags(join(' ', array_unique($tags)));
        $deck->setUser($this->getUser());
				
				foreach ( $cards_to_add as $card) {
					$slot = new Deckslot ();
					$slot->setQuantity ( 1 );
					$slot->setCard ( $card );
					$slot->setDeck ( $deck );
					$deck->addSlot ( $slot );
				}
				
        $em->persist($deck);
        $em->flush();

        return $this->redirect($this->get('router')->generate('deck_edit', ['deck_id' => $deck->getId()]));
    }

    public function importAction ()
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->container->getParameter('cache_expiration'));

        $factions = $this->getDoctrine()->getRepository('AppBundle:Faction')->findAll();
        
        return $this->render('AppBundle:Builder:directimport.html.twig',
                array(
                        'pagetitle' => "Import a deck",
                		'factions' => array_map(function ($faction) { return [ 'code' => $faction->getCode(), 'name' => $faction->getName() ]; }, $factions)
                ), $response);

    }

    public function fileimportAction (Request $request)
    {

        $filetype = filter_var($request->get('type'), FILTER_SANITIZE_STRING);
        $uploadedFile = $request->files->get('upfile');
        if (! isset($uploadedFile))
            return new Response('No file');
        $origname = $uploadedFile->getClientOriginalName();
        $origext = $uploadedFile->getClientOriginalExtension();
        $filename = $uploadedFile->getPathname();

        if (function_exists("finfo_open")) {
            // return mime type ala mimetype extension
            $finfo = finfo_open(FILEINFO_MIME);

            // check to see if the mime-type starts with 'text'
            $is_text = substr(finfo_file($finfo, $filename), 0, 4) == 'text' || substr(finfo_file($finfo, $filename), 0, 15) == "application/xml";
            if (! $is_text)
                return new Response('Bad file');
        }

        if ($filetype == "octgn" || ($filetype == "auto" && $origext == "o8d")) {
            $parse = $this->parseOctgnImport(file_get_contents($filename));
        } else {
            $parse = $this->parseTextImport(file_get_contents($filename));
        }

		$properties = array(
				'name' => str_replace(".$origext", '', $origname),
				'faction_code' => $parse['faction_code'],
				'content' => json_encode($parse['content']),
				'description' => $parse['description']
		);

        return $this->forward('AppBundle:Builder:save', $properties);
    }

    public function parseTextImport ($text)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->getDoctrine()->getManager();

        $content = [];
        $lines = explode("\n", $text);
        $identity = null;
        foreach ($lines as $line) {
            $matches = [];
            if (preg_match('/^\s*(\d)x?([\pLl\pLu\pN\-\.\'\!\: ]+)/u', $line, $matches)) {
                $quantity = intval($matches[1]);
                $name = trim($matches[2]);
            } else
                if (preg_match('/^([^\(]+).*x(\d)/', $line, $matches)) {
                    $quantity = intval($matches[2]);
                    $name = trim($matches[1]);
                } else
                    if (empty($identity) && preg_match('/([^\(]+):([^\(]+)/', $line, $matches)) {
                        $quantity = 1;
                        $name = trim($matches[1] . ":" . $matches[2]);
                        $identity = $name;
                    } else {
                        continue;
                    }
            $card = $em->getRepository('AppBundle:Card')->findOneBy(array(
                    'name' => $name
            ));
            if ($card) {
                $content[$card->getCode()] = $quantity;
            }
        }
        return array(
                "content" => $content,
                "description" => ""
        );

    }

    public function parseOctgnImport ($octgn)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->getDoctrine()->getManager();

        $crawler = new Crawler();
        $crawler->addXmlContent($octgn);
		// read octgnId
        $cardcrawler = $crawler->filter('deck > section > card');
		$octgnIds = [];
		foreach ($cardcrawler as $domElement) {
			$octgnIds[$domElement->getAttribute('id')] = intval($domElement->getAttribute('qty'));
        }
		// read desc
		$desccrawler = $crawler->filter('deck > notes');
        $descriptions = [];
        foreach ($desccrawler as $domElement) {
            $descriptions[] = $domElement->nodeValue;
        }

        $content = [];
		$faction = null;
        foreach ($octgnIds as $octgnId => $qty) {
			$card = $em->getRepository('AppBundle:Card')->findOneBy(array(
                    'octgnId' => $octgnId
            ));
            if ($card) {
                $content[$card->getCode()] = $qty;
            }
			else {
				$faction = $faction ?: $em->getRepository('AppBundle:Faction')->findOneBy(array(
	                    'octgnId' => $octgnId
	            ));
			}
        }

		$description = implode("\n", $descriptions);

        return array(
				"faction_code" => $faction ? $faction->getCode() : '',
                "content" => $content,
                "description" => $description
        );

    }

    public function textexportAction ($deck_id)
    {
		/* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->getDoctrine()->getManager();

        /* @var $deck \AppBundle\Entity\Deck */
        $deck = $em->getRepository('AppBundle:Deck')->find($deck_id);
        
        $is_owner = $this->getUser() && $this->getUser()->getId() == $deck->getUser()->getId();
        if(!$deck->getUser()->getIsShareDecks() && !$is_owner) {
        	return $this->render(
        			'AppBundle:Default:error.html.twig',
        			array(
        					'pagetitle' => "Error",
        					'error' => 'You are not allowed to view this deck. To get access, you can ask the deck owner to enable "Share your decks" on their account.'
        			)
        	);
        }

        $content = $this->renderView('AppBundle:Export:plain.txt.twig', [
        	"deck" => $deck->getTextExport()
      	]);
        $content = str_replace("\n", "\r\n", $content);

		$response = new Response();
		$response->headers->set('Content-Type', 'text/plain');
		$response->headers->set('Content-Disposition', $response->headers->makeDisposition(
		    ResponseHeaderBag::DISPOSITION_ATTACHMENT,
				$this->get('texts')->slugify($deck->getName()) . '.txt'
		));

		$response->setContent($content);
		return $response;

    }

    public function octgnexportAction ($deck_id)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->getDoctrine()->getManager();

        /* @var $deck \AppBundle\Entity\Deck */
        $deck = $em->getRepository('AppBundle:Deck')->find($deck_id);

        $is_owner = $this->getUser() && $this->getUser()->getId() == $deck->getUser()->getId();
        if(!$deck->getUser()->getIsShareDecks() && !$is_owner) {
        	return $this->render(
        			'AppBundle:Default:error.html.twig',
        			array(
        					'pagetitle' => "Error",
        					'error' => 'You are not allowed to view this deck. To get access, you can ask the deck owner to enable "Share your decks" on their account.'
        			)
        	);
        }
        
		$content = $this->renderView('AppBundle:Export:octgn.xml.twig', [
        	"deck" => $deck->getTextExport()
      	]);
        
		$response = new Response();

		$response->headers->set('Content-Type', 'application/octgn');
		$response->headers->set('Content-Disposition', $response->headers->makeDisposition(
				ResponseHeaderBag::DISPOSITION_ATTACHMENT,
				$this->get('texts')->slugify($deck->getName()) . '.o8d'
		));

		$response->setContent($content);
		return $response;
    }

    public function cloneAction ($deck_id)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->getDoctrine()->getManager();

        /* @var $deck \AppBundle\Entity\Deck */
        $deck = $em->getRepository('AppBundle:Deck')->find($deck_id);

        $is_owner = $this->getUser() && $this->getUser()->getId() == $deck->getUser()->getId();
        if(!$deck->getUser()->getIsShareDecks() && !$is_owner) {
            return $this->render(
                'AppBundle:Default:error.html.twig',
                array(
                    'pagetitle' => "Error",
                    'error' => 'You are not allowed to view this deck. To get access, you can ask the deck owner to enable "Share your decks" on their account.'
                )
            );
        }

        $content = [];
        foreach ($deck->getSlots() as $slot) {
            $content[$slot->getCard()->getCode()] = $slot->getQuantity();
        }
        return $this->forward('AppBundle:Builder:save',
            array(
                'name' => $deck->getName().' (clone)',
                'faction_code' => $deck->getFaction()->getCode(),
                'content' => json_encode($content),
                'deck_id' => $deck->getParent() ? $deck->getParent()->getId() : null
            ));

    }

    public function saveAction (Request $request)
    {

        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->getDoctrine()->getManager();

        $user = $this->getUser();
        if (count($user->getDecks()) > $user->getMaxNbDecks())
            return new Response('You have reached the maximum number of decks allowed. Delete some decks or increase your reputation.');

        $id = filter_var($request->get('id'), FILTER_SANITIZE_NUMBER_INT);
        $deck = null;
        $source_deck = null;
        if($id) {
            $deck = $em->getRepository('AppBundle:Deck')->find($id);
            if (!$deck || $user->getId() != $deck->getUser()->getId()) {
                throw new UnauthorizedHttpException("You don't have access to this deck.");
            }
            $source_deck = $deck;
        }

				// XXX
				// check for investigator here
				$investigator = false;
				
        $cancel_edits = (boolean) filter_var($request->get('cancel_edits'), FILTER_SANITIZE_NUMBER_INT);
        if($cancel_edits) {
            if($deck) $this->get('decks')->revertDeck($deck);
            return $this->redirect($this->generateUrl('decks_list'));
        }

        $is_copy = (boolean) filter_var($request->get('copy'), FILTER_SANITIZE_NUMBER_INT);
        if($is_copy || !$id) {
            $deck = new Deck();
        }

        $content = (array) json_decode($request->get('content'));
        if (! count($content)) {
            return new Response('Cannot import empty deck');
        }

        $name = filter_var($request->get('name'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        $decklist_id = filter_var($request->get('decklist_id'), FILTER_SANITIZE_NUMBER_INT);
        $description = trim($request->get('description'));
        $tags = filter_var($request->get('tags'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);

        $this->get('decks')->saveDeck($this->getUser(), $deck, $decklist_id, $name, $investigator, $description, $tags, $content, $source_deck ? $source_deck : null);
        $em->flush();
        
        return $this->redirect($this->generateUrl('decks_list'));

    }

    public function deleteAction (Request $request)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->getDoctrine()->getManager();

        $deck_id = filter_var($request->get('deck_id'), FILTER_SANITIZE_NUMBER_INT);
        $deck = $em->getRepository('AppBundle:Deck')->find($deck_id);
        if (! $deck)
            return $this->redirect($this->generateUrl('decks_list'));
        if ($this->getUser()->getId() != $deck->getUser()->getId())
            throw new UnauthorizedHttpException("You don't have access to this deck.");

        foreach ($deck->getChildren() as $decklist) {
            $decklist->setParent(null);
        }
        $em->remove($deck);
        $em->flush();

        $this->get('session')
            ->getFlashBag()
            ->set('notice', "Deck deleted.");

        return $this->redirect($this->generateUrl('decks_list'));

    }

    public function deleteListAction (Request $request)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->getDoctrine()->getManager();

        $list_id = explode('-', $request->get('ids'));

        foreach($list_id as $id)
        {
            /* @var $deck Deck */
            $deck = $em->getRepository('AppBundle:Deck')->find($id);
            if(!$deck) continue;
            if ($this->getUser()->getId() != $deck->getUser()->getId()) continue;

            foreach ($deck->getChildren() as $decklist) {
                $decklist->setParent(null);
            }
            $em->remove($deck);
        }
        $em->flush();

        $this->get('session')
            ->getFlashBag()
            ->set('notice', "Decks deleted.");

        return $this->redirect($this->generateUrl('decks_list'));
    }

    public function editAction ($deck_id)
    {

        $deck = $this->getDoctrine()->getManager()->getRepository('AppBundle:Deck')->find($deck_id);

        if (!$deck || $this->getUser()->getId() != $deck->getUser()->getId()) 
        {
        	return $this->render(
        		'AppBundle:Default:error.html.twig',
        		array(
        			'pagetitle' => "Error",
        			'error' => 'You are not allowed to view this deck.'
        		)
        	);
        }

        return $this->render(
        	'AppBundle:Builder:deckedit.html.twig',
        	array(
        		'pagetitle' => "Deckbuilder",
        		'deck' => $deck,
        	)
        );

    }

    public function viewAction ($deck_id)
    {
    	$deck = $this->getDoctrine()->getManager()->getRepository('AppBundle:Deck')->find($deck_id);
    	
        if(!$deck) {
        	return $this->render(
        			'AppBundle:Default:error.html.twig',
        			array(
        					'pagetitle' => "Error",
        					'error' => "This deck doesn't exist."
        			)
        	);
        }

        $is_owner = $this->getUser() && $this->getUser()->getId() == $deck->getUser()->getId();
        if(!$deck->getUser()->getIsShareDecks() && !$is_owner) {
			return $this->render(
				'AppBundle:Default:error.html.twig',
				array(
					'pagetitle' => "Error",
					'error' => 'You are not allowed to view this deck. To get access, you can ask the deck owner to enable "Share your decks" on their account.'
				)
			);
        }

        $tournaments = $this->getDoctrine()->getManager()->getRepository('AppBundle:Tournament')->findAll();
        
        return $this->render(
        	'AppBundle:Builder:deckview.html.twig',
        	array(
        		'pagetitle' => "Deckbuilder",
        		'deck' => $deck,
        		'deck_id' => $deck_id,
        		'is_owner' => $is_owner,
        		'tournaments' => $tournaments,
        	)
        );
    }

    public function compareAction($deck1_id, $deck2_id, Request $request)
    {
    	$entityManager = $this->getDoctrine()->getManager();
    
    	/* @var $deck1 \AppBundle\Entity\Deck */
    	$deck1 = $entityManager->getRepository('AppBundle:Deck')->find($deck1_id);
    
    	/* @var $deck2 \AppBundle\Entity\Deck */
    	$deck2 = $entityManager->getRepository('AppBundle:Deck')->find($deck2_id);
    
    	if(!$deck1 || !$deck2) {
    		return $this->render(
    				'AppBundle:Default:error.html.twig',
    				array(
    						'pagetitle' => "Error",
    						'error' => 'This deck cannot be found.'
    				)
    		);
    	}
    	
    	$is_owner = $this->getUser() && $this->getUser()->getId() == $deck1->getUser()->getId();
    	if(!$deck1->getUser()->getIsShareDecks() && !$is_owner) {
    		return $this->render(
    				'AppBundle:Default:error.html.twig',
    				array(
    						'pagetitle' => "Error",
    						'error' => 'You are not allowed to view this deck. To get access, you can ask the deck owner to enable "Share your decks" on their account.'
    				)
    		);
    	}
    	
    	$is_owner = $this->getUser() && $this->getUser()->getId() == $deck2->getUser()->getId();
    	if(!$deck2->getUser()->getIsShareDecks() && !$is_owner) {
    		return $this->render(
    				'AppBundle:Default:error.html.twig',
    				array(
    						'pagetitle' => "Error",
    						'error' => 'You are not allowed to view this deck. To get access, you can ask the deck owner to enable "Share your decks" on their account.'
    				)
    		);
    	}
    	 
    	$plotIntersection = $this->get('diff')->getSlotsDiff([$deck1->getSlots()->getPlotDeck(), $deck2->getSlots()->getPlotDeck()]);
    
    	$drawIntersection = $this->get('diff')->getSlotsDiff([$deck1->getSlots()->getDrawDeck(), $deck2->getSlots()->getDrawDeck()]);
    
    	return $this->render('AppBundle:Compare:deck_compare.html.twig', [
    			'deck1' => $deck1,
    			'deck2' => $deck2,
    			'plot_deck' => $plotIntersection,
    			'draw_deck' => $drawIntersection,
    	]);
    }
    
    public function listAction ()
    {
        /* @var $user \AppBundle\Entity\User */
        $user = $this->getUser();

        $decks = $this->get('decks')->getByUser($user, FALSE);

        $tournaments = $this->getDoctrine()->getConnection()->executeQuery(
                "SELECT
					t.id,
					t.description
                FROM tournament t
                ORDER BY t.description desc")->fetchAll();

        if(count($decks))
        {
			$tags = [];
			foreach($decks as $deck) {
				$tags[] = $deck['tags'];
			}
			$tags = array_unique($tags);
        	return $this->render('AppBundle:Builder:decks.html.twig',
        			array(
        					'pagetitle' => "My Decks",
        					'pagedescription' => "Create custom decks with the help of a powerful deckbuilder.",
        					'decks' => $decks,
							'tags' => $tags,
        					'nbmax' => $user->getMaxNbDecks(),
        					'nbdecks' => count($decks),
        					'cannotcreate' => $user->getMaxNbDecks() <= count($decks),
        					'tournaments' => $tournaments,
        			));

        }
        else
        {
        	return $this->render('AppBundle:Builder:no-decks.html.twig',
        			array(
        					'pagetitle' => "My Decks",
        					'pagedescription' => "Create custom decks with the help of a powerful deckbuilder.",
        					'nbmax' => $user->getMaxNbDecks(),
        					'tournaments' => $tournaments,
        			));
        }
    }

    public function copyAction ($decklist_id)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->getDoctrine()->getManager();

        /* @var $decklist \AppBundle\Entity\Decklist */
        $decklist = $em->getRepository('AppBundle:Decklist')->find($decklist_id);

        $content = [];
        foreach ($decklist->getSlots() as $slot) {
            $content[$slot->getCard()->getCode()] = $slot->getQuantity();
        }
        return $this->forward('AppBundle:Builder:save',
                array(
                        'name' => $decklist->getName(),
                		'faction_code' => $decklist->getFaction()->getCode(),
                        'content' => json_encode($content),
                        'decklist_id' => $decklist_id
                ));

    }

    public function downloadallAction()
    {
        /* @var $user \AppBundle\Entity\User */
        $user = $this->getUser();
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->getDoctrine()->getManager();

        $decks = $this->get('decks')->getByUser($user, FALSE);

        $file = tempnam("tmp", "zip");
        $zip = new \ZipArchive();
        $res = $zip->open($file, \ZipArchive::OVERWRITE);
        if ($res === TRUE)
        {
            foreach($decks as $deck)
            {
                $content = [];
                foreach($deck['cards'] as $slot)
                {
                    $card = $em->getRepository('AppBundle:Card')->findOneBy(array('code' => $slot['card_code']));
                    if(!$card) continue;
                    $cardname = $card->getName();
                    $packname = $card->getPack()->getName();
                    if($packname == 'Core Set') $packname = 'Core';
                    $qty = $slot['qty'];
                    $content[] = "$cardname ($packname) x$qty";
                }
                $filename = str_replace('/', ' ', $deck['name']).'.txt';
                $zip->addFromString($filename, implode("\r\n", $content));
            }
            $zip->close();
        }
        $response = new Response();
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Length', filesize($file));
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
        		ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        		$this->get('texts')->slugify('thronesdb') . '.zip'
        ));
        
        $response->setContent(file_get_contents($file));
        unlink($file);
        return $response;
    }

    public function uploadallAction(Request $request)
    {
        // time-consuming task
        ini_set('max_execution_time', 300);

        $uploadedFile = $request->files->get('uparchive');
        if (! isset($uploadedFile))
            return new Response('No file');

        $filename = $uploadedFile->getPathname();

        if (function_exists("finfo_open")) {
            // return mime type ala mimetype extension
            $finfo = finfo_open(FILEINFO_MIME);

            // check to see if the mime-type is 'zip'
            if(substr(finfo_file($finfo, $filename), 0, 15) !== 'application/zip')
                return new Response('Bad file');
        }

        $zip = new \ZipArchive;
        $res = $zip->open($filename);
        if ($res === TRUE) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                 $name = $zip->getNameIndex($i);
                 $parse = $this->parseTextImport($zip->getFromIndex($i));

                 $deck = new Deck();
                 $em->persist($deck);
                 $this->get('decks')->saveDeck($this->getUser(), $deck, null, $name, '', '', $parse['content']);
            }
        }
        $zip->close();

        $em->flush();
        
        $this->get('session')
            ->getFlashBag()
            ->set('notice', "Decks imported.");

        return $this->redirect($this->generateUrl('decks_list'));
    }

    public function autosaveAction(Request $request)
    {
        $user = $this->getUser();

        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->getDoctrine()->getManager();

        $deck_id = $request->get('deck_id');

        $deck = $em->getRepository('AppBundle:Deck')->find($deck_id);
        if(!$deck) {
            throw new BadRequestHttpException("Cannot find deck ".$deck_id);
        }
        if ($user->getId() != $deck->getUser()->getId()) {
            throw new UnauthorizedHttpException("You don't have access to this deck.");
        }

        $diff = (array) json_decode($request->get('diff'));
        if (count($diff) != 2) {
        	$this->get('logger')->error("cannot use diff", $diff);
            throw new BadRequestHttpException("Wrong content ".json_encode($diff));
        }

        if(count($diff[0]) || count($diff[1])) {
            $change = new Deckchange();
            $change->setDeck($deck);
            $change->setVariation(json_encode($diff));
            $change->setIsSaved(FALSE);
            $em->persist($change);
            $em->flush();
        }

        return new Response($change->getDatecreation()->format('c'));
    }
}
