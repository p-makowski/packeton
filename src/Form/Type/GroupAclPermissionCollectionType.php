<?php

namespace Packeton\Form\Type;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\GroupAclPermission;
use Packeton\Entity\Package;
use Packeton\Form\DataTransformer\GroupAclPermissionsTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GroupAclPermissionCollectionType extends AbstractType
{
    protected $registry;

    /**
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, [$this, 'preSetData'], 255)
            ->addModelTransformer(new GroupAclPermissionsTransformer($this->registry));
    }

    /**
     * @param FormEvent $event
     */
    public function preSetData(FormEvent $event): void
    {
        $data = $event->getData();
        if (null === $data) {
            $data = [];
        }

        if (\is_array($data)) {
            $data = new ArrayCollection($data);
        } elseif ($data instanceof Collection) {
            $data = new ArrayCollection($data->toArray());
        }

        $this->fillEmptyCollectionData($data);
        $event->setData($data);
    }

    protected function fillEmptyCollectionData(Collection $collection)
    {
        $packages = $collection->map(
            function (GroupAclPermission $permission) {
                try {
                    return $permission->getPackage()->getName();
                } catch (EntityNotFoundException) {
                    return null;
                }
            }
        );

        $allPackages = $this->registry->getRepository(Package::class)
            ->findAll();

        foreach ($allPackages as $package) {
            if (false === $packages->contains($package->getName())) {
                $collection->add((new GroupAclPermission())->setPackage($package));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getParent(): string
    {
        return CollectionType::class;
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            [
                'entry_type' => PackagePermissionType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
            ]
        );
    }
}
