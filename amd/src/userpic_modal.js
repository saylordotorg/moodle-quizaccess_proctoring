import ModalFactory from 'core/modal_factory';

export const init = () => {
    document.querySelectorAll('.userpic-modal-trigger').forEach(el => {
        el.addEventListener('click', async () => {
            const imgsrc = el.getAttribute('data-imgsrc');
            const userfullname = el.getAttribute('data-userfullname');

            // Attribute values are decoded when read from the DOM. Keep names and URLs as data
            // when serialising the modal HTML, including names containing quotes or markup.
            const title = document.createElement('span');
            title.textContent = userfullname;
            const body = document.createElement('div');
            body.style.textAlign = 'center';
            const image = document.createElement('img');
            image.setAttribute('src', imgsrc);
            image.setAttribute('alt', userfullname);
            image.style.width = '200px';
            image.style.height = 'auto';
            body.appendChild(image);

            ModalFactory.create({
                title: title.outerHTML,
                body: body.outerHTML,
                type: ModalFactory.types.DEFAULT
            }).then(modal => {
                modal.show();
            });
        });
    });
};
