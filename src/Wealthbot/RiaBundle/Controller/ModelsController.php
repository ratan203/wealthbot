<?php

namespace Wealthbot\RiaBundle\Controller;

use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Serializer\Tests\Model;
use Wealthbot\AdminBundle\Entity\CeModel;
use Wealthbot\AdminBundle\Entity\CeModelEntity;
use Wealthbot\AdminBundle\Form\Handler\CeModelEntityFormHandler;
use Wealthbot\AdminBundle\Form\Handler\CeModelFormHandler;
use Wealthbot\AdminBundle\Form\Handler\ModelAssumptionFormHandler;
use Wealthbot\AdminBundle\Form\Type\CeModelEntityFormType;
use Wealthbot\AdminBundle\Form\Type\CeModelFormType;
use Wealthbot\AdminBundle\Form\Type\ModelAssumptionFormType;
use Wealthbot\AdminBundle\Manager\CeModelManager;
use Wealthbot\AdminBundle\Model\CeModelEntityInterface;
use Wealthbot\ClientBundle\Manager\PortfolioInformationManager;
use Wealthbot\RiaBundle\Entity\RiaCompanyInformation;
use Wealthbot\RiaBundle\Form\Type\ModelRiskRatingFormType;
use Wealthbot\RiaBundle\Form\Type\RiskAdjustmentFormType;
use Wealthbot\UserBundle\Entity\User;

class ModelsController extends Controller
{
    public function indexAction($withLayout = true)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        /** @var $modelManager CeModelManager */
        $modelManager = $this->get('wealthbot_admin.ce_model_manager');

        $user = $this->getUser();

        /** @var CeModel $parentModel */
        $parentModel = $user->getRiaCompanyInformation()->getPortfolioModel();
        $models = $modelManager->getChildModels($parentModel);

        if ($parentModel->isStrategy()) {
            if (isset($ceModels[0])) {
                return $this->redirect($this->generateUrl('rx_ria_default_models', ['slug' => $ceModels[0]->getSlug()]));
            }

            return $this->render('WealthbotRiaBundle:Models:default_index.html.twig', [
                'user' => $user,
                'parent_model' => $parentModel,
                'models' => $models,
            ]);
        }

        $form = $this->createForm(new CeModelFormType($em, $user, $parentModel, false));

        return $this->render('WealthbotRiaBundle:Models:index.html.twig', [
            'user' => $user,
            'models' => $models,
            'form' => $form->createView(),
            'model' => $parentModel,
            'with_layout' => $withLayout,
        ]);
    }

    public function viewAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        /** @var User $user */
        $user = $this->getUser();
        $riaCompanyInformation = $user->getRiaCompanyInformation();
        /** @var $modelManager CeModelManager */
        $modelManager = $this->get('wealthbot_admin.ce_model_manager');
        /** @var $parentModel CeModel */
        $parentModel = $riaCompanyInformation->getPortfolioModel();
        /** @var $model CeModel */
        $model = $modelManager->findCeModelBySlugAndOwnerId($request->get('slug'), $user->getId());

        if (!$parentModel || !$model || $model->getParentId() !== $parentModel->getId()) {
            if ($request->isXmlHttpRequest()) {
                return $this->getJsonResponse([
                    'status' => 'error',
                    'error_message' => 'Model not found',
                ]);
            }

            throw $this->createNotFoundException();
        }

        $models = $modelManager->getChildModels($parentModel);

        $isUseQualified = $riaCompanyInformation->getIsUseQualifiedModels();
        $isQualified = $this->getIsQualifiedModel();

        $form = $this->createForm(new CeModelEntityFormType($model, $em, $user));
        $createModelForm = $this->createForm(new CeModelFormType($em, $user, $parentModel));

        /** @var $portfolioInfoManager PortfolioInformationManager */
        $portfolioInfoManager = $this->get('wealthbot_admin.portfolio_information_manager');

        if ($request->isXmlHttpRequest()) {
            return $this->getJsonResponse([
                'status' => 'success',
                'content' => $this->renderView('WealthbotRiaBundle:Models:_model_view.html.twig', [
                    'form' => $form->createView(),
                    'portfolio_information' => $portfolioInfoManager->getPortfolioInformation($user, $model, $isQualified),
                    'is_use_qualified' => $isUseQualified,
                    'is_show_municipal_bond' => $riaCompanyInformation->getUseMunicipalBond(),
                    'is_show_tax_loss_harvesting' => $riaCompanyInformation->getIsTaxLossHarvesting(),
                ]),
            ]);
        }

        return $this->render('WealthbotRiaBundle:Models:view.html.twig', [
            'form' => $form->createView(),
            'create_model_form' => $createModelForm->createView(),
            'is_use_qualified' => $isUseQualified,
            'parent_model' => $parentModel,
            'models' => $models,
            'portfolio_information' => $portfolioInfoManager->getPortfolioInformation($user, $model, $isQualified),
            'is_show_municipal_bond' => $riaCompanyInformation->getUseMunicipalBond(),
            'is_show_tax_loss_harvesting' => $riaCompanyInformation->getIsTaxLossHarvesting(),
        ]);
    }

    public function modelsAction(Request $request)
    {
        /** @var $modelManager CeModelManager */
        $modelManager = $this->get('wealthbot_admin.ce_model_manager');

        /** @var $portfolioInfoManager PortfolioInformationManager */
        $portfolioInfoManager = $this->get('wealthbot_admin.portfolio_information_manager');

        $user = $this->getUser();

        /** @var $riaCompanyInformation RiaCompanyInformation */
        $riaCompanyInformation = $user->getRiaCompanyInformation();

        $parentModel = $riaCompanyInformation->getPortfolioModel();
        $model = $modelManager->findCeModelBySlugAndOwnerId($request->get('slug'), $user->getId());
        $models = $modelManager->getChildModels($parentModel);

        if (!$parentModel || !$model || $model->getParent()->getId() !== $parentModel->getId()) {
            throw $this->createNotFoundException('Model does not exist.');
        }

        $isQualified = false;
        $isUseQualified = $riaCompanyInformation->getIsUseQualifiedModels();

        if ($isUseQualified) {
            if ($request->get('is_qualified') !== null) {
                $isQualified = $request->get('is_qualified');
                $this->setIsQualifiedModel($isUseQualified);
            }
        }

        $data = [
            'is_use_qualified' => $isUseQualified,
            'parent_model' => $parentModel,
            'models' => $models,
            'portfolio_information' => $portfolioInfoManager->getPortfolioInformation($user, $model, $isQualified),
        ];

        return $this->render('WealthbotRiaBundle:Models:models.html.twig', $data);
    }

    public function modelsPdfAction()
    {
        $modelManager = $this->get('wealthbot_admin.ce_model_manager');
        $portfolioInformationManager = $this->get('wealthbot_admin.portfolio_information_manager');

        /** @var User $user */
        $user = $this->getUser();
        $isUseQualified = $user->getRiaCompanyInformation()->isUseQualifiedModels();
        $parentModel = $user->getRiaCompanyInformation()->getPortfolioModel();

        $portfoliosInformation = [];
        foreach ($modelManager->getChildModels($parentModel) as $model) {
            $portfoliosInformation[] = $portfolioInformationManager->getPortfolioInformation($user, $model, $isUseQualified);
        }

        $html = $this->renderView('WealthbotRiaBundle:Models:models.pdf.twig', [
            'portfolios_information' => $portfoliosInformation,
        ]);

        return new Response(
            $this->get('knp_snappy.pdf')->getOutputFromHtml($html),
            200,
            [
                'Content-Type' => 'application/pdf',
            ]
        );
    }

    public function createAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        $user = $this->getUser();

        /** @var CeModel $parentModel */
        $parentModel = $user->getRiaCompanyInformation()->getPortfolioModel();
        $modelManager = $this->get('wealthbot_admin.ce_model_manager');
        $model = $modelManager->createChild($parentModel);

        $form = $this->createForm(new CeModelFormType($em, $user, $parentModel), $model);
        $formHandler = new CeModelFormHandler($form, $request, $em);

        if ($request->isMethod('post')) {
            if ($formHandler->process()) {
                if ($request->isXmlHttpRequest()) {
                    $form = $this->createForm(new CeModelFormType($em, $user, $parentModel), $modelManager->createChild($parentModel));

                    return $this->getJsonResponse([
                        'form' => $this->renderView('WealthbotRiaBundle:Models:_create_model_form.html.twig', [
                            'form' => $form->createView(),
                            'model_id' => $parentModel->getId(),
                        ]),
                        'models_list' => $this->renderView('WealthbotRiaBundle:Models:_models_list.html.twig', [
                            'models' => $modelManager->getChildModels($parentModel),
                        ]),
                    ]);
                }

                return $this->redirect($this->generateUrl('rx_ria_models'));
            }
        }

        return $this->render('WealthbotRiaBundle:Models:index.html.twig', [
            'user' => $user,
            'model' => $parentModel,
            'models' => $parentModel ? $modelManager->getChildModels($parentModel) : [],
            'form' => $form->createView(),
        ]);
    }

    public function updateModelRiskAction(Request $request)
    {
        /** @var $em EntityManager */
        $em = $this->get('doctrine.orm.entity_manager');

        $model = $em->getRepository('WealthbotAdminBundle:CeModel')->find($request->get('model_id'));
        if (!$model || $model->getOwner()->getRia() !== $this->getUser()) {
            throw $this->createNotFoundException('Model does not exist.');
        }

        $form = $this->createForm(new ModelRiskRatingFormType());

        if ($request->isMethod('post')) {
            $form->handleRequest($request);

            if ($form->isValid()) {
                $model = $form->getData();

                $em->persist($model);
                $em->flush();
            }
        }

        $referer = $request->headers->get('referer');

        return new RedirectResponse($referer);
    }

    public function editAction(Request $request)
    {
        $user = $this->getUser();

        /** @var $parentModel CeModel */
        $parentModel = $user->getRiaCompanyInformation()->getPortfolioModel();

        if (!$parentModel) {
            return $this->getJsonResponse([
                'status' => 'error',
                'message' => 'Ria does not have models.',
            ]);
        }

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        /** @var $modelManager CeModelManager */
        $modelManager = $this->get('wealthbot_admin.ce_model_manager');

        $model = $modelManager->findCeModelBySlugAndOwnerId($request->get('slug'), $user->getId());

        $form = $this->createForm(new CeModelFormType($em, $user, $parentModel, true), $model);

        return $this->getJsonResponse([
            'status' => 'success',
            'content' => $this->renderView('WealthbotRiaBundle:Models:_edit_form.html.twig', [
                'form' => $form->createView(),
                'model' => $model,
            ]),
        ]);
    }

    public function saveAssumptionAction(Request $request)
    {
        $user = $this->getUser();

        /** @var $parentModel CeModel */
        $parentModel = $user->getRiaCompanyInformation()->getPortfolioModel();

        if (!$parentModel) {
            return $this->getJsonResponse([
                'status' => 'error',
                'message' => 'Ria does not have models.',
            ]);
        }

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        /** @var $modelManager CeModelManager */
        $modelManager = $this->get('wealthbot_admin.ce_model_manager');

        /** @var $portfolioInfoManager PortfolioInformationManager */
        $portfolioInfoManager = $this->get('wealthbot_admin.portfolio_information_manager');

        $model = $modelManager->findCeModelBySlugAndOwnerId($request->get('slug'), $user->getId());

        $isQualified = false;
        /** @var RiaCompanyInformation $riaCompanyInformation */
        $riaCompanyInformation = $user->getRiaCompanyInformation();
        $isUseQualified = $user->getRiaCompanyInformation()->getIsUseQualifiedModels();

        if ($isUseQualified) {
            if ($request->get('is_qualified') !== null) {
                $isQualified = $request->get('is_qualified');
                $this->setIsQualifiedModel($isUseQualified);
            }
        }

        $form = $this->createForm(new CeModelFormType($em, $user, $parentModel, true), $model);
        $formHandler = new CeModelFormHandler($form, $request, $em, ['is_show_assumption' => true]);

        if ($formHandler->process()) {
            return $this->getJsonResponse([
                'status' => 'success',
                'models_list' => $this->renderView('WealthbotRiaBundle:Models:_models_list.html.twig', [
                    'models' => $modelManager->getChildModels($parentModel),
                    'active_model_id' => $model->getId(),
                ]),
                'model_view' => $this->renderView('WealthbotRiaBundle:Models:_model_view.html.twig', [
                    'portfolio_information' => $portfolioInfoManager->getPortfolioInformation($user, $model, $isQualified),
                    'form' => $this->createForm(new CeModelEntityFormType($model, $em, $user))->createView(),
                    'is_use_qualified' => $isUseQualified,
                    'is_show_municipal_bond' => $riaCompanyInformation->getUseMunicipalBond(),
                    'is_show_tax_loss_harvesting' => $riaCompanyInformation->getIsTaxLossHarvesting(),
                ]),
            ]);
        }

        return $this->getJsonResponse([
            'status' => 'error',
            'content' => $this->renderView('WealthbotRiaBundle:Models:_edit_form.html.twig', [
                'form' => $form->createView(),
                'model' => $model,
            ]),
        ]);
    }

    public function deleteAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        /** @var $modelManager CeModelManager */
        $modelManager = $this->get('wealthbot_admin.ce_model_manager');
        $user = $this->getUser();

        /** @var $model CeModel */
        $model = $modelManager->findCeModelBySlugAndOwnerId($request->get('slug'), $user->getId());

        if ($model) {
            $clientsWithModel = $em->getRepository('WealthbotUserBundle:User')->getClientsWithModel($model->getId());

            if (empty($clientsWithModel)) {
                $modelManager->deleteModel($model);

                if ($request->isXmlHttpRequest()) {
                    return $this->getJsonResponse([
                        'status' => 'success',
                    ]);
                }
            } else {
                if ($request->isXmlHttpRequest()) {
                    return $this->getJsonResponse([
                        'status' => 'error',
                        'error_message' => 'The model cannot be removed because used by clients.',
                    ]);
                }

                $this->get('session')->getFlashBag()->add('error', 'The model cannot be removed because used by clients.');

                return $this->redirect($this->generateUrl('rx_ria_models'));
            }
        }

        if ($request->isXmlHttpRequest()) {
            return $this->getJsonResponse([
                'status' => 'error',
                'error_message' => 'Model does not exists',
            ]);
        }

        return $this->redirect($this->generateUrl('rx_ria_models'));
    }

    public function updateEntityFormAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        $ceModelEntity = null;
        if ($request->get('model_entity_id')) {
            $ceModelEntity = $em->getRepository('WealthbotAdminBundle:CeModelEntity')->find($request->get('model_entity_id'));
            if (!$ceModelEntity || $ceModelEntity->getModel()->getOwner()->getRia() !== $this->getUser()) {
                throw $this->createNotFoundException();
            }
        }
        /** @var $modelManager CeModelManager */
        $modelManager = $this->get('wealthbot_admin.ce_model_manager');

        $ria = $this->getUser();

        $model = $modelManager->findCeModelBySlugAndOwnerId($request->get('slug'), $ria->getId());

        if (!$model) {
            $result = [
                'status' => 'error',
                'message' => 'Portfolio Model object does not exist.',
            ];

            return $this->getJsonResponse($result);
        }

        $isQualifiedModel = $this->getIsQualifiedModel();

        $form = $this->createForm(new CeModelEntityFormType($model, $em, $ria, $isQualifiedModel), $ceModelEntity);

        $form->handleRequest($request);

        $result = [
            'status' => 'success',
            'content' => $this->renderView('WealthbotRiaBundle:Models:_entity_form_fields.html.twig', ['form' => $form->createView()]),
        ];

        return $this->getJsonResponse($result);
    }

    public function createEntityAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        /** @var $modelManager CeModelManager */
        $modelManager = $this->get('wealthbot_admin.ce_model_manager');

        $user = $this->getUser();

        /** @var RiaCompanyInformation $riaCompanyInformation */
        $riaCompanyInformation = $user->getRiaCompanyInformation();
        $parentModel = $riaCompanyInformation->getPortfolioModel();
        $model = $modelManager->findCeModelBySlugAndOwnerId($request->get('slug'), $user->getId());

        if (!$parentModel || !$model || $parentModel->getId() !== $model->getParentId() || !$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException();
        }

        $isQualifiedModel = $this->getIsQualifiedModel();

        $modelEntity = new CeModelEntity();
        $form = $this->createForm(new CeModelEntityFormType($model, $em, $user, $isQualifiedModel), $modelEntity);
        $formHandler = new CeModelEntityFormHandler($form, $request, $em, [
            'model' => $model,
            'is_qualified' => $this->getIsQualifiedModel(),
        ]);

        if ($formHandler->process()) {
            $newForm = $this->createForm(new CeModelEntityFormType($model, $em, $user, $isQualifiedModel));

            $result = [
                'status' => 'success',
                'form' => $this->renderView('WealthbotRiaBundle:Models:_entity_form.html.twig', [
                    'form' => $newForm->createView(),
                    'model' => $model,
                ]),
                'content' => $this->renderView('WealthbotRiaBundle:Models:_entity_row.html.twig', [
                    'modelEntity' => $modelEntity,
                    'is_show_municipal_bond' => $riaCompanyInformation->getUseMunicipalBond(),
                    'is_show_tax_loss_harvesting' => $riaCompanyInformation->getIsTaxLossHarvesting(),
                ]),
            ];

            return $this->getJsonResponse($result);
        }

        $result = [
            'status' => 'error',
            'form' => $this->renderView('WealthbotRiaBundle:Models:_entity_form.html.twig', [
                'form' => $form->createView(),
                'model' => $model,
            ]),
        ];

        return $this->getJsonResponse($result);
    }

    public function viewEntitiesAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        /** @var $modelManager CeModelManager */
        $modelManager = $this->get('wealthbot_admin.ce_model_manager');

        if ($request->get('is_qualified', null) !== null) {
            $this->setIsQualifiedModel($request->get('is_qualified'));
        }

        $user = $this->getUser();
        /** @var RiaCompanyInformation $riaCompanyInformation */
        $riaCompanyInformation = $user->getRiaCompanyInformation();
        $model = $modelManager->findCeModelBySlugAndOwnerId($request->get('slug'), $user->getId());

        if ($riaCompanyInformation->getIsUseQualifiedModels()) {
            $isQualified = $this->getIsQualifiedModel();
            $modelEntities = $em->getRepository('WealthbotAdminBundle:CeModelEntity')->findBy([
                'modelId' => $model->getId(),
                'isQualified' => $isQualified,
            ]);
        } else {
            $modelEntities = $em->getRepository('WealthbotAdminBundle:CeModelEntity')->findBy([
                'modelId' => $model->getId(),
            ]);
        }

        return $this->render('WealthbotRiaBundle:Models:entities_view.html.twig', [
            'modelEntities' => $modelEntities,
            'is_show_municipal_bond' => $riaCompanyInformation->getUseMunicipalBond(),
            'is_show_tax_loss_harvesting' => $riaCompanyInformation->getIsTaxLossHarvesting(),
        ]);
    }

    public function editModelsAssumptionAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        /** @var $parentModel CeModel */
        $parentModel = $em->getRepository('WealthbotAdminBundle:CeModel')->find($request->get('model_id'));
        if (!$parentModel || $parentModel->getOwner()->getRia() !== $this->getUser()) {
            return $this->getJsonResponse([
                'status' => 'error',
            ]);
        }

        $form = $this->createForm(new ModelAssumptionFormType($em), $parentModel);

        if ($request->isMethod('post')) {
            $formHandler = new ModelAssumptionFormHandler($form, $request, $em);
            if ($formHandler->process()) {
                return $this->getJsonResponse([
                    'status' => 'success',
                ]);
            }

            return $this->getJsonResponse([
                'status' => 'error',
                'content' => $this->renderView('WealthbotAdminBundle:Model:_third_party_model_edit_model_assumption_form.html.twig', [
                    'form' => $form->createView(),
                    'action_url' => $this->generateUrl('rx_ria_models_edit_models_assumption', [
                        'model_id' => $parentModel->getId(),
                    ]),
                ]),
            ]);
        }

        return $this->getJsonResponse([
            'status' => 'success',
            'content' => $this->renderView('WealthbotAdminBundle:Model:_third_party_model_edit_model_assumption_form.html.twig', [
                'form' => $form->createView(),
                'action_url' => $this->generateUrl('rx_ria_models_edit_models_assumption', [
                        'model_id' => $parentModel->getId(),
                    ]
                ),
            ]),
        ]);
    }

    public function editModelAssumptionAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        $model = $em->getRepository('WealthbotAdminBundle:CeModel')->find($request->get('model_id'));

        if (!$model || $model->getOwner()->getRia() !== $this->getUser()) {
            return $this->getJsonResponse([
                'status' => 'error',
            ]);
        }

        $form = $this->createForm(new ModelAssumptionFormType($em), $model);

        if ($request->isMethod('post')) {
            $formHandler = new ModelAssumptionFormHandler($form, $request, $em);

            if ($formHandler->process()) {
                return $this->getJsonResponse([
                    'status' => 'success',
                    'redirect_url' => $this->generateUrl('rx_ria_models', [], true),
                ]);
            }

            return $this->getJsonResponse([
                'status' => 'error',
                'content' => $this->renderView('WealthbotAdminBundle:Model:_third_party_model_edit_model_assumption_form.html.twig', [
                    'form' => $form->createView(),
                    'action_url' => $this->generateUrl('rx_ria_models_edit_model_assumption', [
                            'model_id' => $model->getId(),
                        ]
                    ),
                ]),
            ]);
        }

        return $this->getJsonResponse([
            'status' => 'success',
            'content' => $this->renderView('WealthbotAdminBundle:Model:_third_party_model_edit_model_assumption_form.html.twig', [
                'form' => $form->createView(),
                'action_url' => $this->generateUrl('rx_ria_models_edit_model_assumption', [
                        'model_id' => $model->getId(),
                    ]
                ),
            ]),
        ]);
    }

    public function deleteEntityAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        $user = $this->getUser();

        /** @var $parentModel CeModel */
        $parentModel = $user->getRiaCompanyInformation()->getPortfolioModel();
        if (!$parentModel) {
            throw $this->createNotFoundException();
        }

        /** @var $modelEntity CeModelEntity */
        $modelEntity = $em->getRepository('WealthbotAdminBundle:CeModelEntity')->find($request->get('id'));
        if (!$modelEntity) {
            return $this->getJsonResponse([
                'status' => 'error',
                'message' => 'Model Entity with id: '.$request->get('id').' does not exist.',
            ]);
        }

        /** @var $model CeModel */
        $model = $modelEntity->getModel();

        if ($parentModel->getId() !== $model->getParentId()) {
            return $this->getJsonResponse([
                'status' => 'error',
                'message' => 'You can not delete this model entity.',
            ]);
        }

        $em->remove($modelEntity);
        $em->flush();

        return $this->getJsonResponse(['status' => 'success']);
    }

    public function editEntityAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            throw new \HttpRequestMethodException('Request works only with XmlHttp');
        }

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');

        $user = $this->getUser();

        /** @var RiaCompanyInformation $riaCompanyInformation */
        $riaCompanyInformation = $user->getRiaCompanyInformation();
        $parentModel = $riaCompanyInformation->getPortfolioModel();
        if (!$parentModel) {
            throw $this->createNotFoundException();
        }

        /** @var $modelEntity CeModelEntity */
        $modelEntity = $em->getRepository('WealthbotAdminBundle:CeModelEntity')->find($request->get('id'));
        if (!$modelEntity) {
            return $this->getJsonResponse([
                'status' => 'error',
                'message' => 'Model Entity with id: '.$request->get('id').' does not exist.',
            ]);
        }

        if (!$this->isCanEditEntity($modelEntity)) {
            return $this->getJsonResponse([
                'status' => 'error',
                'message' => 'You can not edit this model entity.',
            ]);
        }

        $model = $modelEntity->getModel();
        if ($model->getParentId() !== $parentModel->getId()) {
            return $this->getJsonResponse([
                'status' => 'error',
                'message' => 'You can not edit this model entity.',
            ]);
        }

        $isQualifiedModel = $this->getIsQualifiedModel();

        $form = $this->createForm(new CeModelEntityFormType($model, $em, $user, $isQualifiedModel), $modelEntity);

        if ($request->isMethod('post')) {
            $formHandler = new CeModelEntityFormHandler($form, $request, $em);

            if ($formHandler->process()) {
                $form = $this->createForm(new CeModelEntityFormType($model, $em, $user, $isQualifiedModel));

                return $this->getJsonResponse([
                    'status' => 'success',
                    'form' => $this->renderView('WealthbotRiaBundle:Models:_entity_form.html.twig', [
                        'form' => $form->createView(),
                        'model' => $model,
                    ]),
                    'content' => $this->renderView('WealthbotRiaBundle:Models:_entity_row.html.twig', [
                        'modelEntity' => $modelEntity,
                        'is_show_municipal_bond' => $riaCompanyInformation->getUseMunicipalBond(),
                        'is_show_tax_loss_harvesting' => $riaCompanyInformation->getIsTaxLossHarvesting(),
                    ]),
                ]);
            }

            return $this->getJsonResponse([
                'status' => 'error',
                'form' => $this->renderView('WealthbotRiaBundle:Models:_edit_entity_form.html.twig', [
                    'form' => $form->createView(),
                    'modelEntity' => $modelEntity,
                ]),
            ]);
        }

        return $this->getJsonResponse([
            'status' => 'success',
            'form' => $this->renderView('WealthbotRiaBundle:Models:_edit_entity_form.html.twig', [
                'form' => $form->createView(),
                'modelEntity' => $modelEntity,
            ]),
        ]);
    }

    public function riskAdjustmentAction(Request $request)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');
        $modelManager = $this->get('wealthbot_admin.ce_model_manager');

        /** @var $user User */
        $user = $this->getUser();
        $companyInformation = $user->getRiaCompanyInformation();
        $model = $companyInformation->getPortfolioModel();

        $portfolioModels = $modelManager->findCeModelsBy(
            ['parentId' => $model->getId(), 'ownerId' => $user->getId(), 'isDeleted' => 0]
        );

        $form = $this->createForm(new RiskAdjustmentFormType($portfolioModels));

        if ($request->isMethod('post')) {
            $form->handleRequest($request);

            if ($form->isValid()) {
                $data = $form->getData();

                foreach ($data['ratings'] as $rating) {
                    $em->persist($rating);
                }

                $em->flush();
            }
        }

        return $this->render('WealthbotRiaBundle:Models:risk_adjustment.html.twig', [
            'form' => $form->createView(),
            'max_rating' => 99,
            'is_custom' => $model->isCustom(),
        ]);
    }

    protected function isCanEditEntity(CeModelEntityInterface $modelEntity)
    {
        $nowDate = new \DateTime();
        $updated = $modelEntity->getUpdated();
        $nbEdits = $modelEntity->getNbEdits();

        if (is_null($updated)) {
            return true;
        }

        $interval = $nowDate->diff($updated);
        $yearDiff = (int) $interval->format('%y%');

        if ($yearDiff === 0 && $nbEdits < 2) {
            return true;
        }

        if ($yearDiff > 0) {
            $modelEntity->setNbEdits(0);

            return true;
        }

        return false;
    }

    protected function getJsonResponse(array $data, $code = 200)
    {
        $response = json_encode($data);

        return new Response($response, $code, ['Content-Type' => 'application/json']);
    }

    /**
     * Set what type of models RIA will be used (qualified or non-qualified).
     *
     * @param bool $value
     */
    protected function setIsQualifiedModel($value)
    {
        /** @var Session $session */
        $session = $this->get('session');
        $session->set('models.is_qualified', (bool) $value);
    }

    /**
     * Set what type of models RIA will be used (qualified or non-qualified).
     *
     * @return bool
     */
    protected function getIsQualifiedModel()
    {
        /** @var Session $session */
        $session = $this->get('session');

        return (bool) $session->get('models.is_qualified', false);
    }
}
